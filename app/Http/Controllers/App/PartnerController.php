<?php

namespace App\Http\Controllers\App;

use App\Enums\AcceptancePolicy;
use App\Enums\FieldGroup;
use App\Enums\ImportTypes;
use App\Enums\Permission;
use App\Enums\SharingScope;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddPartnerRequest;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\PartnerService;
use App\Services\Federation\SharingService;
use App\Services\Federation\SyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PartnerController extends Controller
{
    use FiltersListings;

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
                    'is_hidden' => $partner->isHidden(),
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

    public function show(Request $request, FederationPartner $partner, CategoryVocabulary $categories): Response
    {
        Gate::authorize(Permission::ManageFederation->value);

        return Inertia::render('federation/partners/Show', [
            ...$this->sharedListingsProps($request, $partner, $categories),
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
                'is_hidden' => $partner->isHidden(),
                // null means every group granted (the pre-grants default).
                'field_groups' => $partner->field_groups,
                'acceptance_policy' => $partner->acceptance_policy->value,
                'sharing_scope' => $partner->sharing_scope->value,
                'import_types' => $partner->import_types->value,
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
            'availableSharingScopes' => collect(SharingScope::cases())
                ->map(fn (SharingScope $scope): array => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                    'hint' => __('federation.sharing_scope_hints.'.$scope->value),
                ]),
            'availableImportTypes' => collect(ImportTypes::cases())
                ->map(fn (ImportTypes $types): array => [
                    'value' => $types->value,
                    'label' => $types->label(),
                    'hint' => __('federation.import_type_hints.'.$types->value),
                ]),
        ]);
    }

    /**
     * Change which of this partner's listing types are projected for
     * display. Copies are always stored; a loosened preference publishes
     * the queued backlog immediately, like a loosened acceptance policy.
     */
    public function updateImportTypes(Request $request, FederationPartner $partner, SyncService $sync): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'import_types' => ['required', Rule::enum(ImportTypes::class)],
        ]);

        $partner->update($validated);

        $published = $sync->publishEligibleBacklog($partner);

        activity('federation')
            ->causedBy($request->user())
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain, 'import_types' => $partner->import_types->value])
            ->event('import_types_changed')
            ->log("Import types for {$partner->domain} set to {$partner->import_types->value}");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.import_types_updated', [
                'domain' => $partner->domain,
                'types' => $partner->import_types->label(),
                'published' => $published,
            ]),
        ]);

        return back();
    }

    /**
     * Change what this partner's feed contains: the open catalogue plus
     * explicit shares (standard) or explicit shares only (curated). The
     * flip records a visibility transition per affected listing so the
     * partner's next poll picks up tombstones or resurfaced listings —
     * no listing row is touched.
     */
    public function updateSharingScope(Request $request, FederationPartner $partner, SharingService $sharing): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'sharing_scope' => ['required', Rule::enum(SharingScope::class)],
        ]);

        $result = $sharing->setSharingScope($partner, SharingScope::from($validated['sharing_scope']));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.sharing_scope_updated', [
                'domain' => $partner->domain,
                'scope' => $partner->sharing_scope->label(),
                'hidden' => $result['hidden'],
                'revealed' => $result['revealed'],
            ]),
        ]);

        return back();
    }

    /**
     * The curated picker's props: one type at a time (sale and charter
     * are never mixed in one list — the tab re-visits with the other
     * listing_type), filtered exactly like the own-listing indexes, with
     * each row carrying why the partner can already see it (a direct
     * share, or the named groups) and whether the same vessel is also
     * listed the other way — picking the wrong twin sends the wrong
     * price information.
     *
     * @return array<string, mixed>
     */
    private function sharedListingsProps(Request $request, FederationPartner $partner, CategoryVocabulary $categories): array
    {
        if ($partner->sharing_scope !== SharingScope::Curated) {
            return [];
        }

        $type = $request->query('listing_type') === 'charter' ? 'charter' : 'sale';
        $filters = $this->listingFilters($request);

        /** @var Builder<SaleYacht|CharterYacht> $query */
        $query = $type === 'charter' ? CharterYacht::query() : SaleYacht::query();

        $directUuids = DB::table('listing_audience_partners')
            ->where('federation_partner_id', $partner->id)
            ->pluck('listing_uuid')
            ->all();
        $partnerGroupIds = $partner->groups()->pluck('partner_groups.id')->all();
        $sisterVesselIds = ($type === 'charter' ? SaleYacht::query() : CharterYacht::query())
            ->pluck('vessel_id')
            ->flip();

        return [
            'filters' => $filters,
            'categories' => $categories->all(),
            'builders' => $this->builderOptions(),
            'statuses' => $this->statusOptions(),
            'sharedListings' => [
                'type' => $type,
                // The FULL direct-share list within the type, not just the
                // filtered rows: the picker's save replaces the whole list,
                // so its selection must start from the whole list or saving
                // under a filter would silently unshare everything outside
                // it.
                'direct_uuids' => ($type === 'charter' ? CharterYacht::query() : SaleYacht::query())
                    ->whereIn('uuid', $directUuids)
                    ->pluck('uuid'),
                'items' => $this->applyOwnListingFilters(
                    $query->with(['vessel:id,builder_name,loa_m,year_built', 'audienceGroups:id,name']),
                    $filters,
                )
                    ->orderBy('name')
                    ->get()
                    ->map(fn (SaleYacht|CharterYacht $yacht): array => [
                        'uuid' => $yacht->uuid,
                        'name' => $yacht->name,
                        'status' => $yacht->status->value,
                        'status_label' => $yacht->status->label(),
                        'audience' => $yacht->audience->value,
                        'builder_name' => $yacht->vessel->builder_name,
                        'loa_m' => $yacht->vessel->loa_m,
                        'year_built' => $yacht->vessel->year_built,
                        'price_amount' => $yacht instanceof SaleYacht ? $yacht->price_amount : null,
                        'price_currency' => $yacht instanceof SaleYacht ? $yacht->price_currency : null,
                        'rates' => $yacht instanceof CharterYacht ? ($yacht->rates ?? []) : null,
                        'directly_shared' => in_array($yacht->uuid, $directUuids, true),
                        'via_groups' => $yacht->audienceGroups
                            ->filter(fn (PartnerGroup $group): bool => in_array($group->id, $partnerGroupIds, true))
                            ->pluck('name')
                            ->values(),
                        'has_sister_listing' => $sisterVesselIds->has($yacht->vessel_id),
                    ]),
            ],
        ];
    }

    /**
     * Replace the partner's direct shares within one listing type from
     * the picker. Per-type uuid existence keeps the two tables from ever
     * mixing in one payload.
     */
    public function updateSharedListings(Request $request, FederationPartner $partner, SharingService $sharing): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['sale', 'charter'])],
            'uuids' => ['present', 'array'],
            'uuids.*' => [
                'uuid',
                Rule::exists($request->input('type') === 'charter' ? 'charter_yachts' : 'sale_yachts', 'uuid'),
            ],
        ]);

        $result = $sharing->replaceDirectSharesForPartner($partner, $validated['type'], $validated['uuids']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.shared_listings_updated', [
                'domain' => $partner->domain,
                'hidden' => $result['hidden'],
                'revealed' => $result['revealed'],
            ]),
        ]);

        return back();
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
