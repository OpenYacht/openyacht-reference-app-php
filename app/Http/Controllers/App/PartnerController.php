<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddPartnerRequest;
use App\Models\FederationPartner;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use App\Services\Federation\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PartnerController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::ManageFederation->value);

        return Inertia::render('federation/partners/Index', [
            'partners' => FederationPartner::query()
                ->withCount('listingCopies')
                ->orderBy('domain')
                ->get()
                ->map(fn (FederationPartner $partner): array => [
                    'id' => $partner->id,
                    'domain' => $partner->domain,
                    'trust_level' => $partner->trust_level->value,
                    'trust_level_label' => $partner->trust_level->label(),
                    'listing_copies_count' => $partner->listing_copies_count,
                    'last_ok_at' => $partner->last_ok_at?->diffForHumans(),
                    'last_synced_at' => $partner->last_synced_at?->diffForHumans(),
                    'consecutive_failures' => $partner->consecutive_failures,
                    'is_stale' => $partner->isStale(),
                ]),
        ]);
    }

    public function show(FederationPartner $partner): Response
    {
        Gate::authorize(Permission::ManageFederation->value);

        return Inertia::render('federation/partners/Show', [
            'partner' => [
                'id' => $partner->id,
                'domain' => $partner->domain,
                'node_uuid' => $partner->node_uuid,
                'trust_level' => $partner->trust_level->value,
                'trust_level_label' => $partner->trust_level->label(),
                'keys' => collect($partner->keys_json ?? [])->map(fn (array $key): array => [
                    'key_id' => $key['key_id'],
                    'algorithm' => $key['algorithm'] ?? 'ed25519',
                    'created_at' => $key['created_at'] ?? null,
                    'pinned' => $partner->pinned_key_id === $key['key_id'],
                ])->all(),
                'pinned_key_id' => $partner->pinned_key_id,
                'keys_fetched_at' => $partner->keys_fetched_at?->diffForHumans(),
                'approved_by' => $partner->approvedBy?->name,
                'last_ok_at' => $partner->last_ok_at?->diffForHumans(),
                'last_synced_at' => $partner->last_synced_at?->diffForHumans(),
                'consecutive_failures' => $partner->consecutive_failures,
                'listing_copies_count' => $partner->listingCopies()->count(),
                'is_stale' => $partner->isStale(),
            ],
        ]);
    }

    public function store(AddPartnerRequest $request, PartnerService $partners): RedirectResponse
    {
        try {
            $partner = $partners->add($request->string('domain')->value());
        } catch (InvalidWellKnownDocument $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.partner_added', ['domain' => $partner->domain]),
        ]);

        return to_route('partners.show', $partner);
    }

    public function approve(Request $request, FederationPartner $partner, PartnerService $partners): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $partners->approve($partner, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.partner_approved', ['domain' => $partner->domain]),
        ]);

        return back();
    }

    public function block(Request $request, FederationPartner $partner, PartnerService $partners): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $partners->block($partner, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.partner_blocked', ['domain' => $partner->domain]),
        ]);

        return back();
    }

    public function refreshKeys(Request $request, FederationPartner $partner, PartnerService $partners): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $previousPinnedKeyId = $partner->pinned_key_id;

        try {
            $partner = $partners->refreshKeys($partner, pinConfirmedBy: $request->user());
        } catch (InvalidWellKnownDocument $exception) {
            throw ValidationException::withMessages(['partner' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $partner->pinned_key_id !== $previousPinnedKeyId
                ? __('federation.key_repinned', ['domain' => $partner->domain, 'key_id' => $partner->pinned_key_id])
                : __('federation.keys_refreshed', ['domain' => $partner->domain]),
        ]);

        return back();
    }

    public function sync(FederationPartner $partner, SyncService $sync): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        try {
            $result = $sync->sync($partner);
        } catch (Throwable) {
            throw ValidationException::withMessages(['partner' => __('federation.sync_failed', ['domain' => $partner->domain])]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.sync_completed', [
                'domain' => $partner->domain,
                'created' => $result->created,
                'updated' => $result->updated,
                'tombstoned' => $result->tombstoned,
            ]),
        ]);

        return back();
    }
}
