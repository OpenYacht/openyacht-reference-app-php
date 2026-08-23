<?php

namespace App\Services\Federation;

use App\Enums\FederationErrorCode;

/**
 * Outcome of verifying an inbound federation request signature.
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $verified,
        public ?FederationErrorCode $error,
    ) {}

    public static function passed(): self
    {
        return new self(verified: true, error: null);
    }

    public static function failed(FederationErrorCode $error): self
    {
        return new self(verified: false, error: $error);
    }
}
