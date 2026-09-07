<?php

namespace App\Services\Federation;

use App\Enums\IntroductionOutcome;

/**
 * Outcome of an outbound partnership request (PartnerService::introduce):
 * what happened, and the translated sentence telling the operator.
 */
final readonly class PartnerIntroduction
{
    public function __construct(
        public IntroductionOutcome $outcome,
        public string $message,
    ) {}
}
