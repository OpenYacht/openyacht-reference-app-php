<?php

namespace App\Enums;

/**
 * Partner trust levels.
 *
 * // federation-protocol.md §Trust levels
 */
enum TrustLevel: string
{
    /** Established business relationship, human-approved. */
    case Verified = 'verified';

    /** Known but unapproved; limited or no data shared. Automatic on first contact. */
    case Provisional = 'provisional';

    /** Explicitly refused; all requests rejected. */
    case Blocked = 'blocked';

    /**
     * The translated display label for this trust level.
     */
    public function label(): string
    {
        return __('federation.trust_levels.'.$this->value);
    }
}
