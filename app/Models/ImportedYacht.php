<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Database\Factories\ImportedYachtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A partner listing curated for display on this node. A projection of its
 * listing copy — never a listing of our own (ID-4); the provenance lives
 * on the copy.
 *
 * @property int $id
 * @property int $listing_copy_id
 * @property int|null $imported_by_user_id
 * @property string $name
 * @property string $type
 * @property ListingStatus $status
 * @property string|null $builder_name
 * @property string|null $model_name
 * @property int|null $year_built
 * @property float|null $loa_m
 * @property string|null $price_amount
 * @property string|null $price_currency
 * @property string|null $location_display
 * @property string|null $summary
 * @property string|null $attribution_text
 * @property Carbon|null $media_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'listing_copy_id', 'imported_by_user_id', 'name', 'type', 'status',
    'builder_name', 'model_name', 'year_built', 'loa_m', 'price_amount',
    'price_currency', 'location_display', 'summary', 'attribution_text',
    'media_synced_at', 'auto_published_at',
])]
class ImportedYacht extends Model
{
    /** @use HasFactory<ImportedYachtFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'year_built' => 'integer',
            'loa_m' => 'float',
            'media_synced_at' => 'datetime',
            'auto_published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ListingCopy, $this>
     */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(ListingCopy::class, 'listing_copy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    /**
     * @return HasMany<ImportedMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ImportedMedia::class);
    }

    public function profileMedia(): ?ImportedMedia
    {
        return $this->media->firstWhere('kind', 'profile');
    }
}
