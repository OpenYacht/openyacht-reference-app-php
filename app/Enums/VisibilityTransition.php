<?php

namespace App\Enums;

/**
 * The event types of the append-only visibility log. An event log, not a
 * flag: the re-share case — unshare then re-share must surface as a
 * tombstone THEN a normal listing again against an arbitrary
 * updated_since watermark — cannot be expressed by a lone hidden_at
 * column.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
enum VisibilityTransition: string
{
    case Visible = 'visible';

    case Hidden = 'hidden';

    /**
     * The partner's served view changed (field-group grants) without a
     * visibility change: lifts the listing's effective timestamp for that
     * partner so the next poll resends the re-gated payload (API-4).
     */
    case Refreshed = 'refreshed';
}
