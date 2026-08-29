<?php

namespace App\Models;

use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One ECB daily reference rate: EUR to {currency}. EUR itself is
 * implicitly 1.0 and never stored. Rates exist solely so the data API
 * can compare prices across currencies at query time — stored prices
 * are never rewritten, and there is no fallback rate source: a
 * conversion either has a fetched rate or it is refused loudly.
 *
 * @property int $id
 * @property string $currency
 * @property string $rate
 * @property Carbon $published_at
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /** @var array<string, float|null> */
    private static array $rateCache = [];

    protected $fillable = ['currency', 'rate', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'published_at' => 'date',
        ];
    }

    /**
     * The EUR→currency rate, or null when no rate has been fetched.
     * Cached in memory for the duration of the request.
     */
    public static function rateFor(string $currency): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return 1.0;
        }

        if (! array_key_exists($currency, self::$rateCache)) {
            $rate = static::query()->where('currency', $currency)->value('rate');
            self::$rateCache[$currency] = $rate !== null ? (float) $rate : null;
        }

        return self::$rateCache[$currency];
    }

    /**
     * Convert an amount between currencies, or null when either rate is
     * missing — the caller decides how to refuse; nothing here guesses.
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return round($amount, 2);
        }

        $fromRate = static::rateFor($from);
        $toRate = static::rateFor($to);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        return round($amount * ($toRate / $fromRate), 2);
    }

    public static function flushRateCache(): void
    {
        self::$rateCache = [];
    }
}
