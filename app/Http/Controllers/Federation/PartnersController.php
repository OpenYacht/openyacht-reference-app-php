<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPartner;
use App\Services\Federation\FederationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound partnership requests. The verification middleware has already
 * authenticated the sender and stored it as a (provisional) partner —
 * this endpoint records the human-readable request on the partner row,
 * where the partner page and the first-contact mail show it to the
 * administrator deciding whether to approve (FP-13).
 *
 * Deliberately lenient: neither field is required. A sender that omits
 * both has still introduced itself; the request is recorded either way.
 *
 * // federation-protocol.md §Partner Lifecycle
 */
class PartnersController extends Controller
{
    public function request(Request $request, FederationNotifier $notifier): JsonResponse
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        $message = $request->string('message')->trim()->limit(1000)->value();
        $contactEmail = $request->string('contact_email')->trim()->limit(255)->value();

        $partner->update([
            'request_message' => $message !== '' ? $message : null,
            'request_contact_email' => $contactEmail !== '' ? $contactEmail : null,
            'requested_at' => now(),
        ]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties([
                'domain' => $partner->domain,
                'message' => $message,
                'contact_email' => $contactEmail,
            ])
            ->event('partner_request_received')
            ->log("Partnership requested by {$partner->domain}");

        // A sender the middleware registered just now already rides the
        // queued first-contact mail, which re-fetches this row and so
        // carries the message. A partner known before this request gets
        // its own mail — otherwise the request would be invisible.
        if (! $partner->wasRecentlyCreated) {
            $notifier->partnershipRequested($partner);
        }

        return response()->json([
            'status' => 'received',
            'trust_level' => $partner->trust_level->value,
        ], 202);
    }
}
