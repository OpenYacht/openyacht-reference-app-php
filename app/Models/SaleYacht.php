<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Carbon\CarbonInterface;
use Database\Factories\SaleYachtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A sale listing this node is the authority for.
 *
 * The canonical UUID is minted once at creation and never reused; the
 * canonical URI never changes for the life of the listing (ID-1). Price
 * changes append to the price history, never rewrite it (LS-10). Status
 * follows the lifecycle draft → active ⇄ under_offer → sold | withdrawn,
 * and the terminal states are final — a returning vessel gets a new
 * listing with a new UUID (ID-8).
 *
 * // yacht-identity.md §Listing Identity, §Lifecycle
 *
 * @property int $id
 * @property string $uuid
 * @property int $vessel_id
 * @property int|null $assigned_broker_id
 * @property ListingStatus $status
 * @property string $name
 * @property string|null $summary
 * @property string|null $condition
 * @property string|null $price_amount
 * @property string|null $price_currency
 * @property bool $price_on_application
 * @property bool $starting_price
 * @property string|null $location_display
 * @property string|null $location_city
 * @property string|null $location_state
 * @property string|null $location_country
 * @property string|null $location_marina
 * @property float|null $location_lat
 * @property float|null $location_lon
 * @property array<string, mixed>|null $specifications
 * @property array<int, array{section: string|null, content: string}>|null $descriptions
 * @property array<int, array{category: string|null, name: string, slug: string|null}>|null $features
 * @property array<string, mixed>|null $compliance
 * @property CarbonInterface|null $listed_at
 * @property CarbonInterface|null $federation_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'vessel_id', 'assigned_broker_id', 'status', 'name', 'summary',
    'condition', 'price_amount', 'price_currency', 'price_on_application',
    'starting_price', 'location_display', 'location_city', 'location_state',
    'location_country', 'location_marina', 'location_lat', 'location_lon',
    'specifications', 'descriptions', 'features', 'compliance', 'listed_at',
])]
class SaleYacht extends Model implements HasMedia
{
    /** @use HasFactory<SaleYachtFactory> */
    use HasFactory, InteractsWithMedia;

    protected static function booted(): void
    {
        static::creating(function (SaleYacht $yacht): void {
            // Canonical identity is minted exactly once (ID-1).
            $yacht->uuid ??= (string) Str::uuid7();
            $yacht->status ??= ListingStatus::Draft;
            $yacht->federation_updated_at = now();
        });

        static::created(function (SaleYacht $yacht): void {
            $yacht->appendPriceHistoryIfPriced();
        });

        static::updating(function (SaleYacht $yacht): void {
            if ($yacht->isDirty('uuid')) {
                throw new InvalidArgumentException('The canonical UUID never changes for the life of the listing.');
            }

            $yacht->federation_updated_at = now();
        });

        static::updated(function (SaleYacht $yacht): void {
            if ($yacht->wasChanged(['price_amount', 'price_currency'])) {
                $yacht->appendPriceHistoryIfPriced();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'price_on_application' => 'boolean',
            'starting_price' => 'boolean',
            'location_lat' => 'float',
            'location_lon' => 'float',
            'specifications' => 'array',
            'descriptions' => 'array',
            'features' => 'array',
            'compliance' => 'array',
            'listed_at' => 'datetime',
            'federation_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBroker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_broker_id');
    }

    /**
     * @return HasMany<PriceHistory, $this>
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
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

    private function appendPriceHistoryIfPriced(): void
    {
        if ($this->price_amount === null || $this->price_currency === null) {
            return;
        }

        $this->priceHistory()->create([
            'amount' => $this->price_amount,
            'currency' => $this->price_currency,
            'changed_at' => now(),
        ]);
    }
}
