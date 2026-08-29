<?php

namespace App\Services;

use App\Models\Setting;
use Spatie\Activitylog\Models\Activity;

/**
 * Applies the activity-log retention policy: delete the high-volume
 * operational channels older than the configured window, and never touch
 * the audit trail. The audit events — partnerships established/approved,
 * keys repinned, listings shared/imported/withdrawn/removed, role and
 * user changes — are evidence a node operator may need long after the
 * fact (a brokerage disputing when or whether a listing was displayed),
 * so they are kept forever regardless of retention. Only the ephemeral
 * per-run sync summaries are prunable. A window of 0 keeps even those.
 *
 * Shared by the daily scheduled prune and the manual "run cleanup now"
 * action so both honour the same setting.
 */
class ActivityLogPruner
{
    public const RETENTION_SETTING = 'activity_log_retention_days';

    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Channels safe to prune: operational noise with no evidentiary
     * value. Everything else is audit and is kept forever.
     *
     * @var list<string>
     */
    public const PRUNABLE_CHANNELS = ['sync'];

    public function retentionDays(): int
    {
        return Setting::getInt(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Delete prunable-channel entries older than the retention window.
     * Returns the number of rows removed; 0 when retention is "keep
     * forever". The audit channels are never deleted.
     */
    public function prune(): int
    {
        $days = $this->retentionDays();

        if ($days <= 0) {
            return 0;
        }

        return Activity::query()
            ->whereIn('log_name', self::PRUNABLE_CHANNELS)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
