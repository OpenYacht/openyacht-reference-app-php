<?php

namespace App\Models;

use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A consumer URL that receives this node's outbound "public content
 * changed" pings (see ChangeNotifier). Each endpoint carries its own
 * optional shared secret — sent as X-OpenYacht-Webhook-Secret and never
 * shown again after saving — and its own delivery record, so one node
 * can feed several sites and an operator can see which one is failing.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string|null $secret
 * @property bool $is_active
 * @property int|null $schedule_interval_minutes
 * @property Carbon|null $last_notified_at
 * @property Carbon|null $last_succeeded_at
 * @property Carbon|null $last_failed_at
 * @property int $consecutive_failures
 * @property int|null $created_by_user_id
 */
#[Fillable(['name', 'url', 'secret', 'is_active', 'schedule_interval_minutes', 'created_by_user_id'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * How many delivery attempts are kept per endpoint.
     */
    public const DELIVERY_LOG_SIZE = 50;

    /**
     * Slack applied when deciding whether a scheduled ping is due, so an
     * hourly scheduler tick a few seconds short of the interval does not
     * push the ping a whole hour later.
     */
    public const SCHEDULE_TOLERANCE_MINUTES = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'schedule_interval_minutes' => 'integer',
            'last_notified_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this endpoint's scheduled ping is due: it has a schedule and
     * the interval has elapsed (less the tolerance) since its last
     * notification of any kind. A never-notified endpoint is due at once.
     * Decided in PHP rather than SQL because the interval is per row and
     * date arithmetic is not portable across SQLite, MySQL and MariaDB.
     */
    public function isScheduledPingDue(): bool
    {
        if (! $this->is_active || $this->schedule_interval_minutes === null) {
            return false;
        }

        if ($this->last_notified_at === null) {
            return true;
        }

        return $this->last_notified_at
            ->addMinutes($this->schedule_interval_minutes)
            ->subMinutes(self::SCHEDULE_TOLERANCE_MINUTES)
            ->lessThanOrEqualTo(now());
    }

    /**
     * Human label for the schedule ("every 24 h"), used in reason strings.
     */
    public function scheduleLabel(): ?string
    {
        if ($this->schedule_interval_minutes === null) {
            return null;
        }

        return $this->schedule_interval_minutes % 60 === 0
            ? 'every '.intdiv($this->schedule_interval_minutes, 60).' h'
            : "every {$this->schedule_interval_minutes} min";
    }

    /**
     * Record one delivery attempt, update the endpoint's health summary
     * and trim the per-endpoint log to its bounded size.
     */
    public function recordDelivery(string $reason, int $attempt, bool $succeeded, ?int $httpStatus, ?string $error, int $durationMs): WebhookDelivery
    {
        $delivery = $this->deliveries()->create([
            'reason' => mb_substr($reason, 0, 1000),
            'attempt' => $attempt,
            'succeeded' => $succeeded,
            'http_status' => $httpStatus,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);

        $this->forceFill($succeeded
            ? ['last_succeeded_at' => now(), 'consecutive_failures' => 0]
            : ['last_failed_at' => now(), 'consecutive_failures' => $this->consecutive_failures + 1])
            ->saveQuietly();

        $keep = $this->deliveries()->latest('id')->limit(self::DELIVERY_LOG_SIZE)->pluck('id');
        $this->deliveries()->whereNotIn('id', $keep)->delete();

        return $delivery;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'url', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "Webhook endpoint {$eventName}");
    }
}
