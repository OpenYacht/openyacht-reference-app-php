<?php

namespace App\Jobs;

use App\Models\FederationPartner;
use App\Services\Federation\SignedClient;
use App\Services\Federation\SubscriptionDeliveryFailed;
use App\Services\Federation\SubscriptionService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one listing's current state to one subscribed partner's
 * callback (API-10): the serialised listing or a tombstone, signed with
 * this node's key exactly like any federation request.
 *
 * The payload is derived when the job runs, not when it was queued, so
 * a retry after a later edit carries the newer state (the consumer
 * deduplicates on (id, updated_at), so a repeat is harmless). Unique
 * per (partner, listing) until processing begins, so a burst of edits
 * queues one delivery, not one per keystroke. Dispatched after the
 * surrounding transaction commits so the worker never reads the
 * listing before the change is visible.
 *
 * A failed attempt throws, which the queue turns into a retry on an
 * exponential backoff (one minute doubling, capped at four hours) until
 * the 24-hour window the spec sets closes; only then does the delivery
 * land in failed_jobs and the activity log. Same `notifications` queue
 * as the change pings: time-sensitive, and never behind a media import.
 *
 * // api-design.md §Subscriptions
 */
class DeliverSubscriptionChange implements ShouldBeUniqueUntilProcessing, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const RETRY_WINDOW_HOURS = 24;

    public const INITIAL_BACKOFF_SECONDS = 60;

    public const MAX_BACKOFF_SECONDS = 4 * 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public FederationPartner $partner,
        public string $listingUuid,
    ) {
        $this->onQueue(SendChangeNotification::QUEUE);
    }

    public function uniqueId(): string
    {
        return "{$this->partner->id}:{$this->listingUuid}";
    }

    /**
     * Retry until 24 hours after the delivery was queued (API-10).
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    /**
     * Exponential backoff between attempts, capped; the queue repeats
     * the last value for every attempt beyond the list.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $schedule = [];

        for ($seconds = self::INITIAL_BACKOFF_SECONDS; $seconds < self::MAX_BACKOFF_SECONDS; $seconds *= 2) {
            $schedule[] = $seconds;
        }

        $schedule[] = self::MAX_BACKOFF_SECONDS;

        return $schedule;
    }

    public function handle(SubscriptionService $subscriptions, SignedClient $client): void
    {
        // Re-read: the partner may have unsubscribed, or been blocked or
        // downgraded, while this delivery waited in the queue.
        $partner = $this->partner->fresh();

        if ($partner === null || ! $partner->receivesPushes()) {
            return;
        }

        $listing = $subscriptions->findListing($this->listingUuid);

        if ($listing === null) {
            return;
        }

        $payload = $subscriptions->payloadFor($listing, $partner);

        if ($payload === null) {
            return;
        }

        try {
            $response = $client->postCallback((string) $partner->push_callback_url, $payload);
        } catch (Throwable $exception) {
            $this->recordFailure($partner, $exception->getMessage());

            throw $exception;
        }

        if ($response->successful()) {
            $partner->forceFill(['push_last_delivered_at' => now()])->saveQuietly();

            return;
        }

        $this->recordFailure($partner, "HTTP {$response->status()}");

        throw new SubscriptionDeliveryFailed(
            "Push delivery to {$partner->push_callback_url} for {$this->listingUuid} failed: HTTP {$response->status()}",
        );
    }

    /**
     * The retry window closed: the change reaches this partner on its
     * reconciliation poll instead. Logged where the operator looks.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?? 'unknown error';

        Log::error("Push delivery to {$this->partner->domain} for {$this->listingUuid} abandoned after ".self::RETRY_WINDOW_HOURS." hours: {$reason}");

        activity('federation')
            ->performedOn($this->partner)
            ->withProperties(['domain' => $this->partner->domain, 'listing_uuid' => $this->listingUuid, 'reason' => $reason])
            ->event('push_delivery_abandoned')
            ->log("Push delivery to {$this->partner->domain} abandoned after ".self::RETRY_WINDOW_HOURS.' hours');
    }

    private function recordFailure(FederationPartner $partner, string $reason): void
    {
        $partner->forceFill(['push_last_failed_at' => now()])->saveQuietly();

        Log::warning("Push delivery to {$partner->domain} for {$this->listingUuid} failed (attempt {$this->attempts()}): {$reason}");
    }
}
