<?php

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Enums\TrustLevel;
use App\Http\Controllers\Controller;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\SaleYacht;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing overview. Every widget is permission-gated the same way
 * its underlying page is — a user sees a number only when they could
 * open the screen behind it — and broker-scoped counts scope in the
 * query, exactly like the index pages.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $canSeeOwnListings = $user->can('viewAny', SaleYacht::class);
        $canSeePartnerListings = $user->can('viewAny', ListingCopy::class);
        $canManageFederation = $user->can(Permission::ManageFederation->value);

        return Inertia::render('Dashboard', [
            'fleet' => $canSeeOwnListings ? $this->fleet($user) : null,
            'partnerListings' => $canSeePartnerListings ? $this->partnerListings() : null,
            'federation' => $canManageFederation ? $this->federation() : null,
            'recentListings' => $canSeeOwnListings ? $this->recentListings($user) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fleet(User $user): array
    {
        $scope = fn (Builder $query): Builder => $query->when(
            ! $user->can(Permission::ManageListings->value),
            fn ($query) => $query->where('assigned_broker_id', $user->id),
        );

        $count = fn (Builder $query, ListingStatus ...$statuses): int => (clone $query)
            ->whereIn('status', $statuses)
            ->count();

        $sale = $scope(SaleYacht::query());
        $charter = $scope(CharterYacht::query());

        return [
            'sale_active' => $count($sale, ListingStatus::Active, ListingStatus::UnderOffer),
            'sale_draft' => $count($sale, ListingStatus::Draft),
            'charter_active' => $count($charter, ListingStatus::Active, ListingStatus::UnderOffer),
            'charter_draft' => $count($charter, ListingStatus::Draft),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerListings(): array
    {
        return [
            'synced' => ListingCopy::query()->whereNull('tombstoned_at')->count(),
            'tombstoned' => ListingCopy::query()->whereNotNull('tombstoned_at')->count(),
            'imported' => ImportedYacht::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function federation(): array
    {
        $partners = FederationPartner::query()->get(['id', 'trust_level', 'last_ok_at']);

        return [
            'verified' => $partners->where('trust_level', TrustLevel::Verified)->count(),
            'provisional' => $partners->where('trust_level', TrustLevel::Provisional)->count(),
            'stale' => $partners
                ->filter(fn (FederationPartner $partner): bool => $partner->trust_level === TrustLevel::Verified && $partner->isStale())
                ->count(),
        ];
    }

    /**
     * The most recently touched own listings across both types —
     * federation_updated_at is the "something changed here" clock the
     * protocol itself uses, so it is the honest recency signal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentListings(User $user): array
    {
        $scope = fn (Builder $query): Builder => $query->when(
            ! $user->can(Permission::ManageListings->value),
            fn ($query) => $query->where('assigned_broker_id', $user->id),
        );

        $recent = fn (Builder $query): Collection => $scope($query)
            ->with('media')
            ->orderByDesc('federation_updated_at')
            ->limit(6)
            ->get();

        return $recent(SaleYacht::query())
            ->concat($recent(CharterYacht::query()))
            ->sortByDesc('federation_updated_at')
            ->take(6)
            ->values()
            ->map(fn (SaleYacht|CharterYacht $yacht): array => [
                'id' => $yacht->id,
                'type' => $yacht instanceof CharterYacht ? 'charter' : 'sale',
                'name' => $yacht->name,
                'status' => $yacht->status->value,
                'status_label' => $yacht->status->label(),
                'thumbnail_url' => $yacht->getFirstMediaUrl('profile', 'thumbnail') ?: null,
                'updated_at' => $yacht->federation_updated_at?->diffForHumans(),
            ])
            ->all();
    }
}
