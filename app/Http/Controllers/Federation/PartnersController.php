<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound partnership requests. The verification middleware has already
 * authenticated the sender and stored it as a (provisional) partner —
 * this endpoint records the human-readable request for administrators to
 * review (FP-13).
 *
 * // federation-protocol.md §Partner Lifecycle
 */
class PartnersController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        $message = $request->string('message')->limit(1000)->value();
        $contactEmail = $request->string('contact_email')->limit(255)->value();

        activity('federation')
            ->performedOn($partner)
            ->withProperties([
                'domain' => $partner->domain,
                'message' => $message,
                'contact_email' => $contactEmail,
            ])
            ->event('partner_request_received')
            ->log("Partnership requested by {$partner->domain}");

        return response()->json([
            'status' => 'received',
            'trust_level' => $partner->trust_level->value,
        ], 202);
    }
}
