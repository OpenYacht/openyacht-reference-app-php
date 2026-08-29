<?php

namespace App\Services\Federation;

use App\Jobs\ImportYachtMedia;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Curated imports: projecting a listing copy into displayable local
 * records. The copy remains the source of truth — sync updates propagate
 * into the projection (ID-7), and when a listing ends its projection and
 * cached media are removed per the usage terms (ID-10).
 */
class ImportService
{
    /**
     * Import a copy for display. Tombstoned listings and listings whose
     * usage terms forbid display cannot be imported. An auto import — the
     * partner's acceptance policy publishing with no human in the loop —
     * is stamped so the recently-auto-published feed can surface it for
     * review after, not before.
     */
    public function import(ListingCopy $copy, ?User $importedBy = null, bool $auto = false): ImportedYacht
    {
        if ($copy->tombstoned_at !== null) {
            throw new InvalidArgumentException('A withdrawn or sold listing cannot be imported.');
        }

        if (data_get($copy->payload, 'usage.display') === false) {
            throw new InvalidArgumentException("The listing's usage terms do not permit display.");
        }

        if (! $copy->partner->importsType($copy->type)) {
            throw new InvalidArgumentException(__('federation.import_type_excluded'));
        }

        $yacht = ImportedYacht::query()->updateOrCreate(
            ['listing_copy_id' => $copy->id],
            $this->projection($copy) + [
                'imported_by_user_id' => $importedBy?->id,
                'auto_published_at' => $auto ? now() : null,
            ],
        );

        if ($auto) {
            activity('federation')
                ->performedOn($yacht)
                ->withProperties(['canonical_uri' => $copy->canonical_uri, 'authority' => $copy->authority_domain])
                ->event('auto_published')
                ->log("Auto-published {$yacht->name} from {$copy->authority_domain} per the partner's acceptance policy");
        }

        ImportYachtMedia::dispatch($yacht);

        return $yacht;
    }

    /**
     * Propagate a synced update into the projection; re-import media only
     * when the source media changed (ID-7).
     */
    public function refresh(ListingCopy $copy): void
    {
        $yacht = ImportedYacht::query()->where('listing_copy_id', $copy->id)->first();

        if ($yacht === null) {
            return;
        }

        $yacht->update($this->projection($copy));

        if ($this->mediaChanged($yacht)) {
            ImportYachtMedia::dispatch($yacht);
        }
    }

    /**
     * The listing ended (tombstone). Cease use: remove the projection and
     * every cached media file (ID-10, expires_with_listing).
     */
    public function expire(ListingCopy $copy): void
    {
        $yacht = ImportedYacht::query()->where('listing_copy_id', $copy->id)->first();

        if ($yacht !== null) {
            activity('federation')
                ->withProperties(['name' => $yacht->name, 'authority' => $copy->authority_domain])
                ->event('import_removed')
                ->log("Removed imported listing \"{$yacht->name}\" — withdrawn by {$copy->authority_domain}");

            $this->remove($yacht);
        }
    }

    /**
     * Remove the projection and delete all generated media files.
     */
    public function remove(ImportedYacht $yacht): void
    {
        Storage::disk(config('openyacht.media.disk'))
            ->deleteDirectory("imported/{$yacht->id}");

        $yacht->delete();
    }

    /**
     * The queryable columns extracted from the copy's wire payload.
     *
     * @return array<string, mixed>
     */
    private function projection(ListingCopy $copy): array
    {
        $payload = $copy->payload ?? [];

        return [
            'name' => data_get($payload, 'listing.name') ?? $copy->name ?? 'Unnamed',
            'type' => $copy->type,
            'status' => $copy->status,
            'builder_name' => data_get($payload, 'vessel.builder.name'),
            'model_name' => data_get($payload, 'vessel.model.name'),
            'year_built' => data_get($payload, 'vessel.year_built'),
            'loa_m' => data_get($payload, 'vessel.loa_m'),
            'price_amount' => data_get($payload, 'listing.price.amount'),
            'price_currency' => data_get($payload, 'listing.price.currency'),
            'location_display' => data_get($payload, 'listing.location.display'),
            'summary' => data_get($payload, 'listing.summary'),
            'attribution_text' => data_get($payload, 'usage.attribution_required') === true
                ? data_get($payload, 'usage.attribution_text')
                : null,
        ];
    }

    /**
     * Whether the copy's media differs from the renditions we hold.
     */
    private function mediaChanged(ImportedYacht $yacht): bool
    {
        $sources = ImportYachtMedia::sourceImages($yacht->copy->payload ?? [])
            ->map(fn (array $image): string => $image['url'].'|'.($image['sha256'] ?? ''))
            ->sort()
            ->values();

        $held = $yacht->media()
            ->get()
            ->map(fn ($media): string => $media->source_url.'|'.($media->source_sha256 ?? ''))
            ->sort()
            ->values();

        return $sources->all() !== $held->all();
    }
}
