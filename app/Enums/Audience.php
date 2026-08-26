<?php

namespace App\Enums;

/**
 * A listing's sharing audience. Audience rules compose INSIDE the node's
 * served-set rule (drafts never serve, blocked partners never read) —
 * they only ever narrow it. A selected audience is the union of
 * individually selected partners and the members of selected groups.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
enum Audience: string
{
    case Everyone = 'everyone';
    case Selected = 'selected';
    case None = 'none';

    public function label(): string
    {
        return __('federation.audiences.'.$this->value);
    }
}
