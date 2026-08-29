<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One asking price a listing has had. Rows are append-only: price changes
 * insert, nothing updates or deletes (LS-10).
 *
 * @property int $id
 * @property int $sale_yacht_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $changed_at
 */
#[Fillable(['sale_yacht_id', 'amount', 'currency', 'changed_at'])]
class PriceHistory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SaleYacht, $this>
     */
    public function saleYacht(): BelongsTo
    {
        return $this->belongsTo(SaleYacht::class);
    }
}
