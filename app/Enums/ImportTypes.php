<?php

namespace App\Enums;

/**
 * A partner's import type preference — the consumer-side decision of
 * which listing types this node projects for display. Entirely local,
 * like the acceptance policy it sits beside: sync always stores every
 * copy (the sync substrate stays complete), this only gates whether a
 * copy becomes an imported projection or reaches the review queue.
 *
 * // yacht-identity.md §What everyone else holds
 */
enum ImportTypes: string
{
    case Both = 'both';
    case Sale = 'sale';
    case Charter = 'charter';

    public function label(): string
    {
        return __('federation.import_types.'.$this->value);
    }

    public function accepts(string $type): bool
    {
        return $this === self::Both || $this->value === $type;
    }
}
