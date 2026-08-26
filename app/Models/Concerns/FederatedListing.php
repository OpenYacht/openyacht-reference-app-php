<?php

namespace App\Models\Concerns;

use App\Enums\ListingStatus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The behaviour every own-listing aggregate shares, whatever its type.
 *
 * Sale and charter listings live in separate tables — the table is the
 * type, which is how the type stays immutable (ID-1) — but identity,
 * lifecycle, and media rules are identical: the canonical UUID is minted
 * once at creation and never changes, status follows draft → active ⇄
 * under_offer → sold | withdrawn with terminal states final (ID-8), and
 * every persisted change touches federation_updated_at so updated_since
 * consumers see it (API-2).
 *
 * // yacht-identity.md §Listing Identity, §Lifecycle
 */
trait FederatedListing
{
    protected static function bootFederatedListing(): void
    {
        static::creating(function (self $yacht): void {
            // Canonical identity is minted exactly once (ID-1).
            $yacht->uuid ??= (string) Str::uuid7();
            $yacht->status ??= ListingStatus::Draft;
            $yacht->federation_updated_at = now();
        });

        static::updating(function (self $yacht): void {
            if ($yacht->isDirty('uuid')) {
                throw new InvalidArgumentException('The canonical UUID never changes for the life of the listing.');
            }

            $yacht->federation_updated_at = now();
        });
    }

    /**
     * The listing's canonical URI — its globally unique identifier,
     * immutable for the life of the listing (ID-1).
     */
    public function canonicalUri(): string
    {
        return 'https://'.config('openyacht.domain')."/openyacht/v1/listings/{$this->uuid}";
    }

    /**
     * The transitions the lifecycle allows from the current status (ID-8).
     *
     * @return list<ListingStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            ListingStatus::Draft => [ListingStatus::Active],
            ListingStatus::Active => [ListingStatus::UnderOffer, ListingStatus::Sold, ListingStatus::Withdrawn],
            ListingStatus::UnderOffer => [ListingStatus::Active, ListingStatus::Sold, ListingStatus::Withdrawn],
            ListingStatus::Sold, ListingStatus::Withdrawn => [],
        };
    }

    public function canTransitionTo(ListingStatus $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Apply a lifecycle transition, rejecting anything the lifecycle does
     * not allow (ID-8).
     */
    public function transitionTo(ListingStatus $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new InvalidArgumentException("A {$this->status->value} listing cannot become {$target->value}.");
        }

        $attributes = ['status' => $target];

        if ($target === ListingStatus::Active && $this->listed_at === null) {
            $attributes['listed_at'] = now();
        }

        $this->update($attributes);
    }

    public function registerMediaCollections(): void
    {
        // The explicit hero image (LS-8): a single file, chosen once,
        // deliberately — never "first gallery entry".
        $this->addMediaCollection('profile')->singleFile();

        $this->addMediaCollection('gallery');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // The mandatory authority-served thumbnail of the profile image
        // (LS-8): ~400-640px on the long edge.
        $this->addMediaConversion('thumbnail')
            ->performOnCollections('profile')
            ->nonQueued()
            ->width(640);
    }
}
