<?php

namespace App\Models\Concerns;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Cross-currency price filtering over mixed-currency inventory, shared by
 * the own-listing and imported-listing tables. The search bounds are
 * converted into each currency present in the table (using the daily ECB
 * reference rates) and the constraint becomes an OR of per-currency
 * BETWEEN ranges — index-friendly plain SQL on SQLite, MySQL and
 * MariaDB, and stored prices are never converted or rewritten.
 *
 * Refusals are loud: a target currency without a fetched rate throws
 * (callers turn that into a 422 before applying the scope), and a
 * listing whose currency has no rate simply never matches a priced
 * filter — there is no fallback rate source anywhere.
 */
trait SearchableByPrice
{
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePriceBetween(Builder $query, ?float $min, ?float $max, string $currency): Builder
    {
        $currency = strtoupper($currency);
        $targetRate = ExchangeRate::rateFor($currency);

        if ($targetRate === null) {
            throw new InvalidArgumentException("No exchange rate is available for {$currency}.");
        }

        $inventoryCurrencies = $query->getModel()->newQuery()
            ->whereNotNull('price_currency')
            ->distinct()
            ->pluck('price_currency');

        $ranges = [];

        foreach ($inventoryCurrencies as $inventoryCurrency) {
            if ($inventoryCurrency === $currency) {
                $ranges[$inventoryCurrency] = [$min, $max];

                continue;
            }

            $rate = ExchangeRate::rateFor($inventoryCurrency);

            if ($rate === null) {
                // No rate, no comparability: the listing never matches a
                // priced filter (documented API behaviour, never a guess).
                continue;
            }

            $factor = $rate / $targetRate;

            $ranges[$inventoryCurrency] = [
                $min !== null ? round($min * $factor, 2) : null,
                $max !== null ? round($max * $factor, 2) : null,
            ];
        }

        if ($ranges === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $constraint) use ($ranges): void {
            foreach ($ranges as $inventoryCurrency => [$rangeMin, $rangeMax]) {
                $constraint->orWhere(function (Builder $branch) use ($inventoryCurrency, $rangeMin, $rangeMax): void {
                    $branch->where('price_currency', $inventoryCurrency)
                        ->when($rangeMin !== null, fn (Builder $q) => $q->where('price_amount', '>=', $rangeMin))
                        ->when($rangeMax !== null, fn (Builder $q) => $q->where('price_amount', '<=', $rangeMax));
                });
            }
        });
    }
}
