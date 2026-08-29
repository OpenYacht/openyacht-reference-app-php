<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one change notification to every configured URL. The body is
 * deliberately minimal — timestamp, reason, counts — because consumers
 * that need details ask the data API; the ping only says "something you
 * may have cached has changed". A failing URL is logged and never blocks
 * the others.
 */
class SendChangeNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $reason,
        public array $counts = [],
    ) {}

    public function handle(): void
    {
        $secret = config('openyacht.change_notifications.secret');

        foreach (config('openyacht.change_notifications.urls', []) as $url) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders($secret !== null ? ['X-OpenYacht-Webhook-Secret' => $secret] : [])
                    ->post($url, [
                        'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                        'reason' => $this->reason,
                        'counts' => $this->counts === [] ? (object) [] : $this->counts,
                    ]);

                if ($response->failed()) {
                    Log::warning("Change notification to {$url} returned HTTP {$response->status()}.");
                }
            } catch (\Throwable $exception) {
                Log::warning("Change notification to {$url} failed: {$exception->getMessage()}");
            }
        }
    }
}
