<?php

namespace App\Models\Concerns;

use App\Enums\Audience;
use App\Enums\ListingStatus;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Services\ChangeNotifier;
use App\Services\Federation\SubscriptionService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

            // An audience change alone never moves the wire timestamp:
            // stamping would resend the listing to every partner, when only
            // the affected partners' views changed — the visibility-event
            // log lifts exactly those partners' effective timestamps
            // (SharingService).
            if (array_keys($yacht->getDirty()) === ['audience']) {
                return;
            }

            $yacht->federation_updated_at = now();
        });

        // Local edits that change public output ping the outbound change
        // notifier (consumers that pre-build from the data API rebuild).
        // Only publicly served listings count — draft edits and
        // audience-only changes (federation-facing, not public-facing)
        // stay quiet. These hang off created/updated, not saved, because
        // saved also fires for no-op saves with the previous save's
        // change set still in place.
        static::created(function (self $yacht): void {
            if ($yacht->servesPublicOutput()) {
                app(ChangeNotifier::class)->notify("listing:{$yacht->uuid} updated");
            }
        });

        static::updated(function (self $yacht): void {
            if (array_keys(array_diff_key($yacht->getChanges(), ['updated_at' => true])) === ['audience']) {
                return;
            }

            if ($yacht->servesPublicOutput() || $yacht->servedPublicOutputBeforeSave()) {
                app(ChangeNotifier::class)->notify("listing:{$yacht->uuid} updated");
            }
        });

        // The federation_updated_at stamp is also what push subscribers
        // are told about (api-design.md §Subscriptions): every stamp on
        // a non-draft listing queues a delivery per subscribed partner —
        // the same changes a poll would report. Drafts are never
        // distributed (LS-7); audience-only changes reach the affected
        // partners through the visibility events instead.
        //
        // This one hangs off saved, deliberately: saved fires after every
        // created/updated listener, including a model's own (SaleYacht
        // appends the price-history row in one), so a delivery that runs
        // at once on a synchronous queue serialises the complete listing.
        // The guard is isDirty(), not wasChanged(): during saved the
        // original is not yet synced, so isDirty() is true exactly when
        // THIS save wrote the stamp, and false for the no-op save that
        // still fires saved with the previous save's change set.
        static::saved(function (self $yacht): void {
            if ($yacht->isDirty('federation_updated_at') && $yacht->status !== ListingStatus::Draft) {
                app(SubscriptionService::class)->listingChanged($yacht);
            }
        });

        static::deleted(function (self $yacht): void {
            if ($yacht->servesPublicOutput()) {
                app(ChangeNotifier::class)->notify("listing:{$yacht->uuid} removed");
            }
        });
    }

    /**
     * Whether the listing currently appears in the public data API
     * (which serves active and under-offer listings only).
     */
    public function servesPublicOutput(): bool
    {
        return in_array($this->status, [ListingStatus::Active, ListingStatus::UnderOffer], true);
    }

    private function servedPublicOutputBeforeSave(): bool
    {
        return in_array(
            $this->getOriginal('status'),
            [ListingStatus::Active, ListingStatus::UnderOffer],
            true,
        );
    }

    protected function initializeFederatedListing(): void
    {
        $this->mergeCasts(['audience' => Audience::class]);

        // The attribute-level twin of the column default, so a freshly
        // constructed model answers audience questions before its row is
        // ever re-read.
        $this->attributes['audience'] ??= Audience::Everyone->value;
    }

    /**
     * The individually selected partners of a selected audience, keyed by
     * the canonical UUID (row ids collide across the two listing tables;
     * UUIDs never do).
     *
     * @return BelongsToMany<FederationPartner, $this>
     */
    public function audiencePartners(): BelongsToMany
    {
        return $this->belongsToMany(
            FederationPartner::class,
            'listing_audience_partners',
            'listing_uuid',
            'federation_partner_id',
            'uuid',
        );
    }

    /**
     * The selected partner groups of a selected audience.
     *
     * @return BelongsToMany<PartnerGroup, $this>
     */
    public function audienceGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            PartnerGroup::class,
            'listing_audience_groups',
            'listing_uuid',
            'partner_group_id',
            'uuid',
        );
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

        // GA/deck plans as images; plan PDFs belong in documents.
        $this->addMediaCollection('layouts');

        // Brochures, plan PDFs, sample menus — served only under the
        // documents field group (LS-14).
        $this->addMediaCollection('documents');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // The authority-served thumbnails: mandatory for the profile image
        // (LS-8), one per gallery and layout image (nullable on the wire;
        // LS-16 — being a conversion of the stored file, it is a rendition
        // of the same image by construction). ~400-640px on the long edge.
        $this->addMediaConversion('thumbnail')
            ->performOnCollections('profile', 'gallery', 'layouts')
            ->nonQueued()
            ->width(640);
    }
}
