<?php

namespace App\Enums;

/**
 * Listing lifecycle status.
 *
 * draft → active ⇄ under_offer → sold | withdrawn. Draft listings are never
 * distributed (LS-7); sold and withdrawn are terminal (ID-8).
 *
 * // yacht-identity.md §Lifecycle
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case UnderOffer = 'under_offer';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';

    public function isTerminal(): bool
    {
        return $this === self::Sold || $this === self::Withdrawn;
    }

    /**
     * The translated display label for this status.
     */
    public function label(): string
    {
        return __('listings.status.'.$this->value);
    }
}
