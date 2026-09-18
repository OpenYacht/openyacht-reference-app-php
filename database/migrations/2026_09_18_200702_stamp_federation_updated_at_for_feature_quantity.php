<?php

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use Illuminate\Database\Migrations\Migration;

/**
 * Adopting quantity on feature (listing-schema.md §Features, LS-1) changes
 * the wire form of every listing that carries features: each entry now
 * emits quantity, null for rows stored before the field existed. The same
 * release stops a blank specifications.category serialising as
 * {name: null} — it is null, as the schema's vocab def requires. Standing
 * rule: a migration that changes what a listing serves on the wire MUST
 * stamp federation_updated_at for the affected listings — scoped to exactly
 * the listings whose serialization changed, not the whole catalog — so
 * updated_since consumers re-fetch the new serialization (API-2; findings
 * issue 5 / spec follow-up 8).
 *
 * There is no backfill: the serializer completes the shape at send time.
 * Sold and withdrawn listings serve tombstones, which carry neither field,
 * so they are left alone. The affected rows are picked in PHP — JSON null
 * and array-length predicates differ across SQLite, MySQL and MariaDB, and
 * an own-listing catalog is small.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stamp = now();

        foreach ([SaleYacht::class, CharterYacht::class] as $model) {
            $affected = $model::query()
                ->get(['id', 'status', 'features', 'specifications'])
                ->filter(fn (SaleYacht|CharterYacht $yacht): bool => ! $yacht->status->isTerminal() && (
                    ($yacht->features ?? []) !== []
                    || (is_array(data_get($yacht->specifications, 'category'))
                        && ! is_string(data_get($yacht->specifications, 'category.name')))
                ))
                ->modelKeys();

            $model::query()
                ->whereKey($affected)
                ->update(['federation_updated_at' => $stamp]);
        }
    }

    public function down(): void
    {
        // A data migration: the moved timestamps stand — reverting them
        // would hide listings from consumers that already synced past them.
    }
};
