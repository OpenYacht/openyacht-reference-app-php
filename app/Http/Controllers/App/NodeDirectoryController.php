<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\FlashesIntroduction;
use App\Http\Controllers\Controller;
use App\Models\FederationPartner;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\NodeDirectory;
use App\Services\Federation\NodeDirectoryIndex;
use App\Services\Federation\PartnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The node directory admin page: browse the advisory phonebook, and
 * manage this node's own listing consent. Entries convey existence only
 * (FP-16) — adding one as a partner runs the ordinary TOFU path, and
 * this node's findability is decided solely by the signed listing
 * requests generated here.
 *
 * // federation-protocol.md §Finding partners: the node directory
 */
class NodeDirectoryController extends Controller
{
    use FlashesIntroduction;

    public function index(NodeDirectory $directory, NodeDirectoryIndex $index): Response
    {
        Gate::authorize(Permission::ManageFederation->value);

        $ownDomain = strtolower((string) config('openyacht.domain'));
        $partnerDomains = FederationPartner::query()->pluck('domain')->all();
        $entries = collect($index->entries());

        // The token and signature are meant to be pasted publicly into
        // the project's listing form, so precomputing them exposes
        // nothing. Null when the node has no identity or keys yet.
        $listingRequests = collect(NodeDirectory::ACTIONS)
            ->mapWithKeys(fn (string $action): array => [
                $action => rescue(fn (): array => $directory->listingRequest($action), rescue: null, report: false),
            ])
            ->filter()
            ->all();

        return Inertia::render('federation/directory/Index', [
            'domain' => $ownDomain !== '' ? $ownDomain : null,
            'listed' => $ownDomain !== '' && $entries->firstWhere('domain', $ownDomain) !== null,
            'listingRequests' => $listingRequests !== [] ? $listingRequests : null,
            'issueFormUrl' => 'https://github.com/OpenYacht/protocol/issues/new?template=node-listing.yml',
            'directory' => $entries
                ->map(fn (array $entry): array => $entry + [
                    'status' => $entry['domain'] === $ownDomain
                        ? 'self'
                        : (in_array($entry['domain'], $partnerDomains, true) ? 'partner' : 'available'),
                ])
                ->values()
                ->all(),
            'fetchedAt' => $index->fetchedAt(),
        ]);
    }

    /**
     * Refresh the vendored node directory from the canonical URL — the
     * only place it can be refreshed from (FP-16).
     */
    public function refresh(NodeDirectoryIndex $index): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        try {
            $count = $index->refresh();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['directory' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.directory_refreshed', ['count' => $count]),
        ]);

        return back();
    }

    /**
     * Add a directory entry as a partner. Deliberately the same trust
     * path as a hand-typed domain: the entry conveys existence only
     * (FP-16); verification is TOFU against the node's own well-known
     * document and the partner starts provisional.
     */
    public function addPartner(Request $request, NodeDirectoryIndex $index, PartnerService $partners): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate(['domain' => ['required', 'string', 'max:255']]);
        $domain = strtolower(trim($validated['domain']));

        $listed = collect($index->entries())->firstWhere('domain', $domain) !== null;

        if (! $listed || $domain === strtolower((string) config('openyacht.domain'))) {
            throw ValidationException::withMessages(['domain' => __('federation.directory_not_listed')]);
        }

        if (FederationPartner::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => __('federation.domain_exists')]);
        }

        try {
            $partner = $partners->add($domain);
        } catch (InvalidWellKnownDocument $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }

        // A directory entry conveys existence only; the signed request is
        // what puts this node in front of their administrators.
        $this->flashIntroduction($partners->introduce($partner, null, $request->user()?->email));

        return back();
    }
}
