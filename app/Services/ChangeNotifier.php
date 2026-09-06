<?php

namespace App\Services;

use App\Jobs\SendChangeNotification;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Outbound "public content changed" pings for consumers that cache or
 * pre-build this node's output — a static-site deploy hook being the
 * canonical example, though nothing here knows or cares which vendor
 * receives the POST. The receiving URLs are WebhookEndpoint rows managed
 * in the admin, each with its own secret and delivery record.
 *
 * Notifications fire once per applied change batch (a sync cycle, an
 * admin edit), never per listing, and are debounced by a cache cooldown
 * so a burst of edits produces one ping. Delivery is one queued job per
 * active endpoint, so a consumer that is down retries on its own without
 * holding up the others. The reason string is logged and sent because it
 * answers "why isn't my update showing yet" when read beside a
 * consumer's build history.
 *
 * Each endpoint may also carry a scheduled interval — the "freshness
 * floor" for consumers that build from data this node never sees change
 * (exchange rates, partners' well-known documents). notifyScheduled(),
 * run hourly by the scheduler, pings an endpoint only when that interval
 * has elapsed since its last ping of any kind, so a node that is already
 * sending change notifications never adds a scheduled one on top.
 */
class ChangeNotifier
{
    private const COOLDOWN_CACHE_KEY = 'openyacht:change-notification-cooldown';

    /**
     * Queue a notification to every active endpoint. Returns true when
     * one was queued; false when no endpoint is active or the cooldown
     * swallowed this one ($force bypasses the cooldown for schedules and
     * manual triggers).
     *
     * @param  array<string, int>  $counts
     */
    public function notify(string $reason, array $counts = [], bool $force = false): bool
    {
        $endpoints = WebhookEndpoint::query()->active()->get();

        if ($endpoints->isEmpty()) {
            return false;
        }

        $cooldownMinutes = max((int) config('openyacht.change_notifications.cooldown_minutes'), 0);

        if (! $force && $cooldownMinutes > 0
            && ! Cache::add(self::COOLDOWN_CACHE_KEY, now()->toIso8601String(), now()->addMinutes($cooldownMinutes))) {
            Log::debug("Change notification debounced ({$reason}).");

            return false;
        }

        Log::info("Change notification queued: {$reason}", $counts);

        foreach ($endpoints as $endpoint) {
            $this->dispatch($endpoint, $reason, $counts);
        }

        return true;
    }

    /**
     * Queue a scheduled ping to every endpoint whose interval has elapsed.
     * Returns how many were queued.
     */
    public function notifyScheduled(): int
    {
        $due = WebhookEndpoint::query()->active()->whereNotNull('schedule_interval_minutes')->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->isScheduledPingDue());

        foreach ($due as $endpoint) {
            $reason = "scheduled:{$endpoint->scheduleLabel()}";
            Log::info("Scheduled change notification queued to {$endpoint->url}: {$reason}");
            $this->dispatch($endpoint, $reason);
        }

        return $due->count();
    }

    /**
     * Queue a test ping to one endpoint regardless of its active flag or
     * the cooldown — the admin's "does this URL work" button.
     */
    public function test(WebhookEndpoint $endpoint): void
    {
        $this->dispatch($endpoint, 'test');
    }

    /**
     * Every ping goes through here so last_notified_at — what the
     * scheduled floor measures from — is stamped for every kind of ping.
     *
     * @param  array<string, int>  $counts
     */
    private function dispatch(WebhookEndpoint $endpoint, string $reason, array $counts = []): void
    {
        $endpoint->forceFill(['last_notified_at' => now()])->saveQuietly();

        SendChangeNotification::dispatch($endpoint, $reason, $counts);
    }
}
