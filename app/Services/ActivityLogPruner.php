<?php

namespace App\Services;

use App\Models\Setting;
use Spatie\Activitylog\Models\Activity;

/**
 * Applies the activity-log retention policy: delete entries older than
 * the configured window. A window of 0 means keep everything forever.
 * Shared by the daily scheduled prune and the manual "run cleanup now"
 * action so both honour the same setting.
 */
class ActivityLogPruner
{
    public const RETENTION_SETTING = 'activity_log_retention_days';

    public const DEFAULT_RETENTION_DAYS = 90;

    public function retentionDays(): int
    {
        return Setting::getInt(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Delete entries older than the retention window. Returns the number
     * of rows removed; 0 when retention is "keep forever".
     */
    public function prune(): int
    {
        $days = $this->retentionDays();

        if ($days <= 0) {
            return 0;
        }

        return Activity::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
