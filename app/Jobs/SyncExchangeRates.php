<?php

namespace App\Jobs;

use App\Models\ExchangeRate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetches the ECB daily reference rates (the eurofxref XML feed — free,
 * keyless, ~30 currencies, EUR-based) into the exchange_rates table.
 * Scheduled daily; failures retry and then fail loudly. There is
 * deliberately no fallback rate source: stale rows keep their
 * published_at date, and anything that cannot be converted from fetched
 * rates is refused by the consumer, never approximated.
 */
class SyncExchangeRates implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 30;

    public function handle(): void
    {
        $url = config('openyacht.exchange_rates.ecb_url');

        $response = Http::timeout(15)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("The ECB rate feed returned HTTP {$response->status()}.");
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException('The ECB rate feed response was not parseable XML.');
        }

        $xml->registerXPathNamespace('euref', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        $rateNodes = $xml->xpath('//euref:Cube[@currency]') ?: [];

        if ($rateNodes === []) {
            throw new RuntimeException('The ECB rate feed contained no currency rates.');
        }

        $dateNodes = $xml->xpath('//euref:Cube[@time]') ?: [];
        $publishedAt = $dateNodes !== [] ? (string) $dateNodes[0]['time'] : now()->toDateString();

        $count = 0;

        foreach ($rateNodes as $node) {
            $currency = strtoupper((string) $node['currency']);
            $rate = (float) $node['rate'];

            if (strlen($currency) === 3 && $rate > 0) {
                ExchangeRate::query()->updateOrCreate(
                    ['currency' => $currency],
                    ['rate' => $rate, 'published_at' => $publishedAt],
                );
                $count++;
            }
        }

        ExchangeRate::flushRateCache();

        Log::info("Exchange rates synced from the ECB: {$count} currencies.", [
            'published_at' => $publishedAt,
        ]);
    }
}
