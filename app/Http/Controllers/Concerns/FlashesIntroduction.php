<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\IntroductionOutcome;
use App\Services\Federation\PartnerIntroduction;
use Inertia\Inertia;

/**
 * The toast for an outbound partnership request, shared by every add
 * path and the re-send. The partner row is saved whatever happened, so
 * a failed introduction is a warning about the other side, not a
 * failed add.
 */
trait FlashesIntroduction
{
    private function flashIntroduction(PartnerIntroduction $introduction): void
    {
        Inertia::flash('toast', [
            'type' => match ($introduction->outcome) {
                IntroductionOutcome::Accepted => 'success',
                IntroductionOutcome::Delivered => 'info',
                IntroductionOutcome::Blocked => 'warning',
                IntroductionOutcome::Failed => 'error',
            },
            'message' => $introduction->message,
        ]);
    }
}
