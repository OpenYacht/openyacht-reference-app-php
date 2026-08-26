<?php

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adopting gallery thumbnail_url (listing-schema.md §Media, LS-16) changes
 * the wire form of every listing that carries gallery media. Standing rule:
 * a migration that changes what a listing serves on the wire MUST stamp
 * federation_updated_at for the affected listings — scoped to exactly the
 * listings whose serialization changed, not the whole catalog — so
 * updated_since consumers re-fetch the new serialization instead of keeping
 * the old shape indefinitely (API-2; findings issue 5 / spec follow-up 8).
 *
 * The thumbnail conversions are generated here too, before the stamp:
 * stamping first would let consumers re-fetch while thumbnail_url is still
 * null, then never see the URLs appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Media::query()
            ->where('collection_name', 'gallery')
            ->cursor()
            ->each(function (Media $media): void {
                try {
                    app(FileManipulator::class)->createDerivedFiles($media, ['thumbnail'], onlyMissing: true);
                } catch (Throwable $exception) {
                    // A missing source file must not abort the migration; the
                    // serializer emits thumbnail_url: null for this item.
                    Log::warning('openyacht.gallery_thumbnail_backfill_failed', [
                        'media_id' => $media->id,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            });

        $stamp = now();

        foreach ([SaleYacht::class, CharterYacht::class] as $model) {
            $model::query()
                ->whereIn('id', Media::query()
                    ->where('collection_name', 'gallery')
                    ->where('model_type', $model)
                    ->select('model_id'))
                ->update(['federation_updated_at' => $stamp]);
        }
    }

    public function down(): void
    {
        // A data migration: the generated conversions and moved timestamps
        // stand — reverting timestamps would hide listings from consumers
        // that already synced past them.
    }
};
