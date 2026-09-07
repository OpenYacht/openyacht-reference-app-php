<?php

namespace App\Models;

use App\Enums\VisibilityTransition;
use App\Services\Federation\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One append-only per-partner visibility transition (became-hidden /
 * became-visible / refreshed). The feed joins each listing to a
 * partner's latest event: GREATEST(federation_updated_at, occurred_at)
 * is the listing's effective timestamp for that partner, which is what
 * lets unshare → poll → re-share → poll deliver tombstone-then-listing
 * against any updated_since watermark.
 *
 * Rows are only ever appended — never updated or deleted. Each new
 * row is also one of the two change sources for push subscriptions:
 * the affected partner, if subscribed, is sent the tombstone or the
 * resurfaced listing at once instead of on its next poll.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 *
 * @property int $id
 * @property string $listing_uuid
 * @property int $federation_partner_id
 * @property VisibilityTransition $event
 * @property Carbon $occurred_at
 */
class VisibilityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['listing_uuid', 'federation_partner_id', 'event', 'occurred_at'];

    protected static function booted(): void
    {
        static::created(function (self $event): void {
            app(SubscriptionService::class)->visibilityChanged($event);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => VisibilityTransition::class,
            'occurred_at' => 'datetime',
        ];
    }
}
