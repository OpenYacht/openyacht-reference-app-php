<?php

namespace App\Enums;

/**
 * A partner's outbound sharing scope — the authority-side decision of
 * whether the partner receives the open catalogue or only a curated
 * selection. Entirely local policy: the wire format and the feed
 * contract are unchanged; the scope only parameterises which listings
 * this node's /listings answer contains for the partner.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
enum SharingScope: string
{
    /**
     * Receives every everyone-audience listing plus anything explicitly
     * shared. The default — today's behaviour for all partners.
     */
    case Standard = 'standard';

    /**
     * Receives only listings explicitly shared with it, directly or via
     * a group. The everyone audience does not include curated partners —
     * built for show organisers, trials, and other limited partners.
     */
    case Curated = 'curated';

    public function label(): string
    {
        return __('federation.sharing_scopes.'.$this->value);
    }
}
