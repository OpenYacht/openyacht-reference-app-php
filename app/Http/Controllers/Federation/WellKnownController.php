<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\WellKnownDocument;
use Illuminate\Http\JsonResponse;

/**
 * Serves the discovery document (FP-1).
 *
 * // federation-protocol.md §Discovery: the well-known endpoint
 */
class WellKnownController extends Controller
{
    public function __invoke(WellKnownDocument $document): JsonResponse
    {
        return response()->json($document->toArray());
    }
}
