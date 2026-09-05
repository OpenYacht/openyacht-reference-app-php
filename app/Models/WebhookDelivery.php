<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver a change notification to a WebhookEndpoint.
 * Append-only and bounded per endpoint (WebhookEndpoint::recordDelivery
 * trims the oldest), so the admin can answer "did my rebuild fire, and
 * if not, what did the consumer say".
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $reason
 * @property int $attempt
 * @property bool $succeeded
 * @property int|null $http_status
 * @property string|null $error
 * @property int $duration_ms
 * @property Carbon $created_at
 */
#[Fillable(['reason', 'attempt', 'succeeded', 'http_status', 'error', 'duration_ms', 'created_at'])]
class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'succeeded' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
