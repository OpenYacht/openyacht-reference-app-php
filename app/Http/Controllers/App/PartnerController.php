<?php

namespace App\Http\Controllers\App;

use App\Enums\AcceptancePolicy;
use App\Enums\FieldGroup;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddPartnerRequest;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use App\Services\Federation\SharingService;
use App\Services\Federation\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'groups' => PartnerGroup::query()
                ->with('members:id,domain,node_name')
                ->orderBy('name')
                ->get()
                ->map(fn (PartnerGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'member_ids' => $group->members->modelKeys(),
                    'acceptance_policy' => $group->acceptance_policy?->value,
                ]),
            'acceptancePolicyOptions' => collect(AcceptancePolicy::cases())
                ->map(fn (AcceptancePolicy $policy): array => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
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
                // null means every group granted (the pre-grants default).
                'field_groups' => $partner->field_groups,
                'acceptance_policy' => $partner->acceptance_policy->value,
                // Group membership can loosen the effective policy: the
                // most permissive of the partner's own setting and its
                // groups' wins.
                'effective_acceptance_policy' => $partner->effectiveAcceptancePolicy()->value,
                'policy_groups' => $partner->groups()
                    ->whereNotNull('acceptance_policy')
                    ->get()
                    ->map(fn (PartnerGroup $group): array => [
                        'name' => $group->name,
                        'policy_label' => $group->acceptance_policy->label(),
                    ]),
            ],
            'availableFieldGroups' => collect(FieldGroup::cases())
                ->map(fn (FieldGroup $group): array => [
                    'value' => $group->value,
                    'label' => __('federation.field_groups.'.$group->value),
                ]),
            'availableAcceptancePolicies' => collect(AcceptancePolicy::cases())
                ->map(fn (AcceptancePolicy $policy): array => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                    'hint' => __('federation.acceptance_policy_hints.'.$policy->value),
                ]),
        ]);
    }

    /**
     * Change what happens to this partner's listings after sync. Sync
     * always stores copies; this only decides whether they are also
     * published without a person clicking per listing — the exception
     * queue (unreviewed conflicts, incomplete listings) still reaches a
     * human either way.
     */
    public function updateAcceptancePolicy(Request $request, FederationPartner $partner, SyncService $sync): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'acceptance_policy' => ['required', Rule::enum(AcceptancePolicy::class)],
        ]);

        $partner->update($validated);

        // A loosened policy publishes the queued backlog immediately —
        // waiting for each listing's next upstream change would make the
        // setting look broken.
        $published = $sync->publishEligibleBacklog($partner);

        activity('federation')
            ->causedBy($request->user())
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain, 'acceptance_policy' => $partner->acceptance_policy->value])
            ->event('acceptance_policy_changed')
            ->log("Acceptance policy for {$partner->domain} set to {$partner->acceptance_policy->value}");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.acceptance_policy_updated', [
                'domain' => $partner->domain,
                'policy' => $partner->acceptance_policy->label(),
                'published' => $published,
            ]),
        ]);

        return back();
    }

    /**
     * Change the partner's field-group grants, then lift every visible
     * listing's effective timestamp for this partner (a refreshed
     * visibility event each) so its next poll picks up the re-gated
     * payloads instead of waiting for the next content change (API-4).
     */
    public function updateFieldGroups(Request $request, FederationPartner $partner, SharingService $sharing): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'field_groups' => ['present', 'array'],
            'field_groups.*' => [Rule::enum(FieldGroup::class)],
        ]);

        $partner->update(['field_groups' => array_values($validated['field_groups'])]);
        $refreshed = $sharing->refreshPartnerFeed($partner);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.field_groups_updated', [
                'domain' => $partner->domain,
                'refreshed' => $refreshed,
            ]),
        ]);

        return back();
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
