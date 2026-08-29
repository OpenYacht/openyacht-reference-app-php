<?php

namespace App\Services;

use App\Jobs\SendChangeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Outbound "public content changed" pings for consumers that cache or
 * pre-build this node's output — a static-site deploy hook being the
 * canonical example, though nothing here knows or cares which vendor
 * receives the POST.
 *
 * Notifications fire once per applied change batch (a sync cycle, an
 * admin edit), never per listing, and are debounced by a cache cooldown
 * so a burst of edits produces one ping. The reason string is logged and
 * sent because it answers "why isn't my update showing yet" when read
 * beside a consumer's build history.
 */
class ChangeNotifier
{
    private const COOLDOWN_CACHE_KEY = 'openyacht:change-notification-cooldown';

    /**
     * Queue a notification to every configured URL. Returns true when
     * one was queued; false when notifications are unconfigured or the
     * cooldown swallowed this one ($force bypasses the cooldown for
     * schedules and manual triggers).
     *
     * @param  array<string, int>  $counts
     */
    public function notify(string $reason, array $counts = [], bool $force = false): bool
    {
        $urls = config('openyacht.change_notifications.urls', []);

        if ($urls === []) {
            return false;
        }

        $cooldownMinutes = max((int) config('openyacht.change_notifications.cooldown_minutes'), 0);

        if (! $force && $cooldownMinutes > 0
            && ! Cache::add(self::COOLDOWN_CACHE_KEY, now()->toIso8601String(), now()->addMinutes($cooldownMinutes))) {
            Log::debug("Change notification debounced ({$reason}).");

            return false;
        }

        Log::info("Change notification queued: {$reason}", $counts);

        SendChangeNotification::dispatch($reason, $counts);

        return true;
    }
}
