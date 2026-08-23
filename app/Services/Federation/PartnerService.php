<?php

namespace App\Services\Federation;

use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\User;

/**
 * Partner lifecycle: first contact (TOFU), key refresh with reinstall
 * detection, and the human approve/block decisions.
 *
 * // federation-protocol.md §Identity and Trust Model, §Partner Lifecycle
 */
class PartnerService
{
    public function __construct(private WellKnownClient $wellKnown) {}

    /**
     * Add a partner by domain. Trust on first use: the keys and node UUID
     * the domain serves now become the stored identity; the partner starts
     * as provisional until a human approves (FP-13).
     */
    public function add(string $domain): FederationPartner
    {
        $domain = strtolower(trim($domain));
        $document = $this->wellKnown->fetch($domain);

        $partner = FederationPartner::create([
            'domain' => $domain,
            'node_uuid' => data_get($document, 'node.uuid'),
            'keys_json' => $document['keys'],
            'keys_fetched_at' => now(),
            'trust_level' => TrustLevel::Provisional,
            'last_ok_at' => now(),
        ]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $domain])
            ->event('partner_added')
            ->log("Partner {$domain} added (provisional)");

        return $partner;
    }

    /**
     * Refetch the partner's well-known document and update the cached
     * keys. A changed node UUID means the domain now hosts a different
     * installation: downgrade to provisional and notify administrators
     * (FP-11).
     */
    public function refreshKeys(FederationPartner $partner): FederationPartner
    {
        $document = $this->wellKnown->fetch($partner->domain);
        $freshUuid = data_get($document, 'node.uuid');
        $previousUuid = $partner->node_uuid;

        if ($previousUuid !== null && $freshUuid !== $previousUuid) {
            $partner->update([
                'node_uuid' => $freshUuid,
                'keys_json' => $document['keys'],
                'keys_fetched_at' => now(),
                'trust_level' => TrustLevel::Provisional,
                'approved_by_user_id' => null,
            ]);

            activity('federation')
                ->performedOn($partner)
                ->withProperties(['domain' => $partner->domain, 'previous_uuid' => $previousUuid, 'new_uuid' => $freshUuid])
                ->event('partner_uuid_changed')
                ->log("Node UUID changed for {$partner->domain} — downgraded to provisional pending re-approval");

            return $partner->refresh();
        }

        $partner->update([
            'keys_json' => $document['keys'],
            'keys_fetched_at' => now(),
        ]);

        return $partner->refresh();
    }

    /**
     * Human approval: the partner becomes verified (FP-13).
     */
    public function approve(FederationPartner $partner, User $approvedBy): FederationPartner
    {
        $partner->update([
            'trust_level' => TrustLevel::Verified,
            'approved_by_user_id' => $approvedBy->id,
        ]);

        activity('federation')
            ->causedBy($approvedBy)
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain])
            ->event('partner_approved')
            ->log("Partner {$partner->domain} approved");

        return $partner->refresh();
    }

    /**
     * Explicit refusal: all requests from this partner are rejected (FP-9).
     */
    public function block(FederationPartner $partner, User $blockedBy): FederationPartner
    {
        $partner->update([
            'trust_level' => TrustLevel::Blocked,
        ]);

        activity('federation')
            ->causedBy($blockedBy)
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain])
            ->event('partner_blocked')
            ->log("Partner {$partner->domain} blocked");

        return $partner->refresh();
    }
}
