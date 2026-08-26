<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Models\Concerns\FederatedListing;
use Carbon\CarbonInterface;
use Database\Factories\SaleYachtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A sale listing this node is the authority for.
 *
 * Identity, lifecycle, and media rules are shared with CharterYacht via
 * the FederatedListing concern; what is sale-specific here is the asking
 * price, whose changes append to the price history, never rewrite it
 * (LS-10).
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
    use FederatedListing, HasFactory, InteractsWithMedia {
        // Spatie ships empty defaults for these hooks; the listing
        // concern's LS-8 implementations are the real ones.
        FederatedListing::registerMediaCollections insteadof InteractsWithMedia;
        FederatedListing::registerMediaConversions insteadof InteractsWithMedia;
    }

    protected static function booted(): void
    {
        static::created(function (SaleYacht $yacht): void {
            $yacht->appendPriceHistoryIfPriced();
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
