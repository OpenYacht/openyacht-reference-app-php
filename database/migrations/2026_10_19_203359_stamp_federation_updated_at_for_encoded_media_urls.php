<?php

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use Illuminate\Database\Migrations\Migration;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The serializer now percent-encodes media URLs (listing-schema.md §Media:
 * url is format uri). medialibrary already did so on local disks, but on
 * object storage a file name outside the URI-safe characters — the seeded
 * "test-pattern-—-gallery-1.jpg", or any broker upload with an accent —
 * went out raw and failed the schema. Those URLs change now. Standing
 * rule: a migration that changes what a listing serves on the wire MUST
 * stamp federation_updated_at for exactly the affected listings (API-2;
 * findings issue 5 / spec follow-up 8). Sold and withdrawn listings serve
 * tombstones, which carry no media, so they are left alone.
 *
 * The affected media are picked in PHP — whether a name needs encoding is
 * not a portable SQL predicate, and an own-listing catalog is small.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stamp = now();

        foreach ([SaleYacht::class, CharterYacht::class] as $model) {
            $listingIds = Media::query()
                ->where('model_type', (new $model)->getMorphClass())
                ->get(['model_id', 'disk', 'file_name'])
                ->filter(fn (Media $media): bool => config("filesystems.disks.{$media->disk}.driver") !== 'local'
                    && rawurlencode($media->file_name) !== $media->file_name)
                ->pluck('model_id')
                ->unique();

            $affected = $model::query()
                ->whereKey($listingIds)
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
        // A data migration: the moved timestamps stand — reverting them
        // would hide listings from consumers that already synced past them.
    }
};
