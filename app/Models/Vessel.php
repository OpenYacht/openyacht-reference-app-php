<?php

namespace App\Models;

use Database\Factories\VesselFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The physical boat. Identifiers are for matching, not authority
 * (yacht-identity.md §Vessel Identity); the builder slug, when non-null,
 * is validated against the vendored registry at data entry (LS-11).
 *
 * @property int $id
 * @property string|null $hin
 * @property string|null $imo
 * @property string|null $mmsi
 * @property string|null $official_number
 * @property string|null $builder_name
 * @property string|null $builder_slug
 * @property string|null $model_name
 * @property string|null $model_slug
 * @property int|null $year_built
 * @property int|null $refit_year
 * @property float|null $loa_m
 * @property array<int, string>|null $previous_names
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'hin', 'imo', 'mmsi', 'official_number', 'builder_name', 'builder_slug',
    'model_name', 'model_slug', 'year_built', 'refit_year', 'loa_m',
    'previous_names',
])]
class Vessel extends Model
{
    /** @use HasFactory<VesselFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_built' => 'integer',
            'refit_year' => 'integer',
            'loa_m' => 'float',
            'previous_names' => 'array',
        ];
    }

    /**
     * @return HasMany<SaleYacht, $this>
     */
    public function saleYachts(): HasMany
    {
        return $this->hasMany(SaleYacht::class);
    }
}
