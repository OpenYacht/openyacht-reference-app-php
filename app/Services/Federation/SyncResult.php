<?php

namespace App\Services\Federation;

/**
 * Outcome of one sync run against one partner.
 */
final readonly class SyncResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $tombstoned = 0,
    ) {}

    public function total(): int
    {
        return $this->created + $this->updated + $this->tombstoned;
    }
}
