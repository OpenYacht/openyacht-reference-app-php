<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Unsigned capability negotiation (API-6).
 *
 * `features` lists optional protocol features only — the sale-listing
 * schema and updated_since sync are the mandatory baseline and carry no
 * flag. `charter_listings` governs whether the node implements the
 * charter block of the wire schema, not what inventory it holds
 * (api-design.md): this node authors and serves charter listings, so it
 * advertises true even when it happens to hold none. `subscriptions`
 * is the optional push layer (api-design.md §Subscriptions): this node
 * accepts callback registrations and delivers signed pushes (API-10).
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
                'subscriptions' => true,
                'charter_listings' => true,
                'media_hashes' => true,
            ],
            'limits' => [
                'page_size_max' => config('openyacht.limits.page_size_max'),
                'rate_per_hour' => config('openyacht.limits.rate_per_hour'),
            ],
        ]);
    }
}
