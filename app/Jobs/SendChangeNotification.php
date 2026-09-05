<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one change notification to one webhook endpoint. The body is
 * deliberately minimal — timestamp, reason, counts — because consumers
 * that need details ask the data API; the ping only says "something you
 * may have cached has changed". Every attempt is written to the
 * endpoint's delivery log; a failed attempt is released back to the
 * queue with a backoff rather than thrown, so a consumer's 500 never
 * lands in failed_jobs, and gives up after $tries.
 */
class SendChangeNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $reason,
        public array $counts = [],
    ) {}

    public function handle(): void
    {
        $startedAt = hrtime(true);
        $httpStatus = null;
        $error = null;

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->endpoint->secret !== null ? ['X-OpenYacht-Webhook-Secret' => $this->endpoint->secret] : [])
                ->post($this->endpoint->url, [
                    'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'reason' => $this->reason,
                    'counts' => $this->counts === [] ? (object) [] : $this->counts,
                ]);

            $httpStatus = $response->status();

            if ($response->failed()) {
                $error = "HTTP {$httpStatus}";
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $attempt = $this->attempts() > 0 ? $this->attempts() : 1;
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $this->endpoint->recordDelivery($this->reason, $attempt, $error === null, $httpStatus, $error, $durationMs);

        if ($error === null) {
            return;
        }

        Log::warning("Change notification to {$this->endpoint->url} failed (attempt {$attempt}): {$error}");

        if ($attempt < $this->tries && $this->job !== null) {
            $this->release($this->backoff);
        }
    }
}
