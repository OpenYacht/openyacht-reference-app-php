<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Unsigned liveness check (API-6).
 *
 * // federation-protocol.md §Health and Failure Handling
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
