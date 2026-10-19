<?php

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use App\Models\Vessel;
use Illuminate\Database\Migrations\Migration;

/**
 * Builder registry 2026.10.0 removed 22 slugs that did not identify a
 * builder. A non-null slug on the wire is a claim of registry membership
 * (LS-11), so a vessel still carrying one of them must fall back to the
 * name alone: the slug is nulled and builder_name, which the broker typed
 * or the registry filled, is kept as the free-text builder.
 *
 * Standing rule: a migration that changes what a listing serves on the
 * wire MUST stamp federation_updated_at for exactly the affected listings
 * (API-2; findings issue 5 / spec follow-up 8). Sold and withdrawn listings
 * serve tombstones, which carry no vessel, so they are left alone.
 *
 * // listing-schema.md §Shared vocabulary — The builder registry
 */
return new class extends Migration
{
    /** @var list<string> */
    private const REMOVED_SLUGS = [
        'blackwater', 'bluewater', 'ch-marine', 'concept', 'eclipse',
        'fishing-raptor', 'grand-alaskan', 'heritage-east', 'marlago',
        'mediterranean', 'northstar', 'parker', 'real', 'reef-runner', 'saga',
        'ses-yachts', 'shearwater', 'strike', 'topaz', 'triton',
        'ultra-lite-tenders', 'vision',
    ];

    public function up(): void
    {
        $vesselIds = Vessel::query()
            ->whereIn('builder_slug', self::REMOVED_SLUGS)
            ->pluck('id');

        if ($vesselIds->isEmpty()) {
            return;
        }

        Vessel::query()->whereKey($vesselIds)->update(['builder_slug' => null]);

        $stamp = now();

        foreach ([SaleYacht::class, CharterYacht::class] as $model) {
            $affected = $model::query()
                ->whereIn('vessel_id', $vesselIds)
                ->get(['id', 'status'])
                ->reject(fn (SaleYacht|CharterYacht $yacht): bool => $yacht->status->isTerminal())
                ->modelKeys();

            $model::query()
                ->whereKey($affected)
                ->update(['federation_updated_at' => $stamp]);
        }
    }

    public function down(): void
    {
        // A data migration: the removed slugs are no longer registry
        // members, so restoring them would put invalid claims on the wire.
    }
};
