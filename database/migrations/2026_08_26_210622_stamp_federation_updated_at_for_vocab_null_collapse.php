<?php

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use Illuminate\Database\Migrations\Migration;

/**
 * The serializer now collapses vessel.builder / vessel.model to null
 * when the name is unknown (the vocab def anchors on a non-null name)
 * and listing.location to null when no location field is set — two
 * schema-conformance fixes found by the WP plugin's input-time
 * validation. That changes the wire form of exactly the listings
 * carrying such gaps, so their federation_updated_at is stamped per the
 * wire-changing-migration rule (.ai/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        $stamp = now();

        foreach ([SaleYacht::class, CharterYacht::class] as $model) {
            $model::query()
                ->where(function ($query): void {
                    $query
                        ->whereHas('vessel', function ($vessel): void {
                            $vessel->where(function ($name): void {
                                $name->whereNull('builder_name')->orWhereNull('model_name');
                            });
                        })
                        ->orWhere(function ($listing): void {
                            $listing
                                ->whereNull('location_display')
                                ->whereNull('location_city')
                                ->whereNull('location_state')
                                ->whereNull('location_country')
                                ->whereNull('location_marina')
                                ->where(function ($coords): void {
                                    $coords->whereNull('location_lat')->orWhereNull('location_lon');
                                });
                        });
                })
                ->update(['federation_updated_at' => $stamp]);
        }
    }

    public function down(): void
    {
        // Data migration: the moved timestamps stand.
    }
};
