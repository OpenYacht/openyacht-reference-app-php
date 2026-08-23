<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Unsigned capability negotiation (API-6).
 *
 * This node advertises no optional features yet: `features` lists optional
 * protocol features only — the sale-listing schema and updated_since sync
 * are the mandatory baseline and carry no flag.
 *
 * // api-design.md §Capabilities
 */
class CapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'protocol_versions' => config('openyacht.protocol_versions'),
            'features' => [
                'subscriptions' => false,
                'charter_listings' => false,
                'media_hashes' => true,
            ],
            'limits' => [
                'page_size_max' => config('openyacht.limits.page_size_max'),
                'rate_per_hour' => config('openyacht.limits.rate_per_hour'),
            ],
        ]);
    }
}
