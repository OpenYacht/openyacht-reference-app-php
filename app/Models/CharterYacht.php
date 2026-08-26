<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\ListingStatus;
use App\Models\Concerns\FederatedListing;
use Carbon\CarbonInterface;
use Database\Factories\CharterYachtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A charter listing this node is the authority for.
 *
 * Identity, lifecycle, and media rules are shared with SaleYacht via the
 * FederatedListing concern; the type itself is structural — sale and
 * charter never share a table, so a listing's type is immutable the same
 * way its UUID is (ID-1). Charter listings carry no asking price (the
 * wire's listing.price is null by design); their pricing is the rate
 * block. Crew data is distributed only while crew_attested_at records a
 * charter-manager/captain attestation (LS-15).
 *
 * // yacht-identity.md §Listing Identity, listing-schema.md §Charter
 *
 * @property int $id
 * @property string $uuid
 * @property int $vessel_id
 * @property int|null $assigned_broker_id
 * @property ListingStatus $status
 * @property string $name
 * @property string|null $summary
 * @property string|null $condition
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
 * @property array<int, array<string, mixed>>|null $rates
 * @property array<int, array{name: string, slug: string|null, season: string|null}>|null $operating_areas
 * @property string|null $summer_base_port
 * @property string|null $winter_base_port
 * @property array<int, array<string, mixed>>|null $crew
 * @property CarbonInterface|null $crew_attested_at
 * @property array<int, array{url: string, caption: string|null}>|null $videos
 * @property array<int, array{url: string, caption: string|null}>|null $tours
 * @property Audience $audience
 * @property CarbonInterface|null $listed_at
 * @property CarbonInterface|null $federation_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'vessel_id', 'assigned_broker_id', 'status', 'name', 'summary',
    'condition', 'location_display', 'location_city', 'location_state',
    'location_country', 'location_marina', 'location_lat', 'location_lon',
    'specifications', 'descriptions', 'features', 'compliance',
    'rates', 'operating_areas', 'summer_base_port', 'winter_base_port',
    'crew', 'crew_attested_at', 'videos', 'tours', 'listed_at',
])]
class CharterYacht extends Model implements HasMedia
{
    /** @use HasFactory<CharterYachtFactory> */
    use FederatedListing, HasFactory, InteractsWithMedia {
        // Spatie ships empty defaults for these hooks; the listing
        // concern's LS-8 implementations are the real ones.
        FederatedListing::registerMediaCollections insteadof InteractsWithMedia;
        FederatedListing::registerMediaConversions insteadof InteractsWithMedia;
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
            'location_lat' => 'float',
            'location_lon' => 'float',
            'specifications' => 'array',
            'descriptions' => 'array',
            'features' => 'array',
            'compliance' => 'array',
            'videos' => 'array',
            'tours' => 'array',
            'rates' => 'array',
            'operating_areas' => 'array',
            'crew' => 'array',
            'crew_attested_at' => 'datetime',
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
}
