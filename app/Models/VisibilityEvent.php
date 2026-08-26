<?php

namespace App\Models;

use App\Enums\VisibilityTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only per-partner visibility transition (became-hidden /
 * became-visible / refreshed). The feed joins each listing to a
 * partner's latest event: GREATEST(federation_updated_at, occurred_at)
 * is the listing's effective timestamp for that partner, which is what
 * lets unshare → poll → re-share → poll deliver tombstone-then-listing
 * against any updated_since watermark.
 *
 * Rows are only ever appended — never updated or deleted.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
class VisibilityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['listing_uuid', 'federation_partner_id', 'event', 'occurred_at'];

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
