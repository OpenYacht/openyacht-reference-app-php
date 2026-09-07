<?php

namespace App\Http\Controllers\Federation;

use App\Enums\FederationErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Responses\FederationErrorResponse;
use App\Models\FederationPartner;
use App\Services\Federation\BlockedOutboundHost;
use App\Services\Federation\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Push subscription registration for verified partners (API-10): one
 * HTTPS callback per partner, replacing any previous one; DELETE removes
 * it. The verification middleware has already authenticated the sender
 * and required a verified partnership — the same bar as the feed.
 *
 * The callback is partner-supplied and this node will POST to it from
 * the server, so it passes the outbound guard before it is stored
 * (FP-14): plain HTTPS, public host, no port, no userinfo.
 *
 * // api-design.md §Subscriptions
 */
class SubscriptionsController extends Controller
{
    public function store(Request $request, SubscriptionService $subscriptions): JsonResponse|Response
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        $callback = $request->json('callback');

        if (! is_string($callback) || $callback === '' || strlen($callback) > 2000) {
            return FederationErrorResponse::make(
                FederationErrorCode::ValidationError,
                'callback must be an HTTPS URL.',
                ['callback' => ['required', 'string', 'max:2000']],
            );
        }

        try {
            $subscriptions->register($partner, $callback);
        } catch (BlockedOutboundHost $exception) {
            return FederationErrorResponse::make(
                FederationErrorCode::ValidationError,
                'callback must be a plain HTTPS URL on a public host.',
                ['callback' => [$exception->getMessage()]],
            );
        }

        return response()->noContent();
    }

    public function destroy(Request $request, SubscriptionService $subscriptions): Response
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        $subscriptions->unregister($partner);

        return response()->noContent();
    }
}
