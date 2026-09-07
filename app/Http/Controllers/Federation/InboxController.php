<?php

namespace App\Http\Controllers\Federation;

use App\Enums\FederationErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Responses\FederationErrorResponse;
use App\Models\FederationPartner;
use App\Services\ChangeNotifier;
use App\Services\Federation\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The callback this node registers with partners it subscribes to
 * (API-11): an authority POSTs one changed listing or tombstone here,
 * signed like any federation request, and the verification middleware
 * authenticates it exactly as it would a poll. Only partners this node
 * actually subscribed to are accepted — a push from anyone else is
 * unsolicited and answered NOT_FOUND.
 *
 * Deliveries are at-least-once, so a repeat of one already applied is
 * acknowledged and ignored (deduplicated on (id, updated_at) against
 * the stored copy); everything else goes through the same application
 * path as a polled item, and an applied delivery notifies the outbound
 * change webhooks like any other applied batch. Any 2xx counts as
 * delivered to the sender.
 *
 * // api-design.md §Subscriptions
 */
class InboxController extends Controller
{
    public function __invoke(Request $request, SyncService $sync, ChangeNotifier $notifier): JsonResponse
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        if (! $partner->isPushSubscribed()) {
            return FederationErrorResponse::make(FederationErrorCode::NotFound, 'This node holds no subscription with the sender.');
        }

        $item = $request->json()->all();

        if (! is_string($item['id'] ?? null) || ! is_string($item['updated_at'] ?? null)) {
            return FederationErrorResponse::make(
                FederationErrorCode::ValidationError,
                'A delivery is one listing or tombstone object with id and updated_at.',
                ['id' => ['required', 'string'], 'updated_at' => ['required', 'string']],
            );
        }

        $outcome = $sync->applyDelivery($partner, $item);

        Log::info('openyacht.push_received', [
            'partner' => $partner->domain,
            'id' => $item['id'],
            'updated_at' => $item['updated_at'],
            'outcome' => $outcome,
        ]);

        if (in_array($outcome, ['created', 'updated', 'tombstoned'], true)) {
            $notifier->notify("push:{$partner->domain} {$outcome} {$item['id']}");
        }

        return response()->json(['status' => 'received', 'outcome' => $outcome]);
    }
}
