<?php

namespace App\Services\Federation;

use App\Enums\Permission as PermissionEnum;
use App\Models\FederationPartner;
use App\Models\User;
use App\Notifications\PartnerFirstContact;
use App\Notifications\PartnerNodeUuidChanged;
use App\Notifications\PartnershipRequested;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

/**
 * Email alerts for the federation events a human must act on: an
 * unsolicited introduction from an unknown node — provisional until
 * approved, so nobody noticing means nobody federating (FP-13) — a
 * partnership request from a node already known here, and a partner's
 * node UUID changing, a possible domain takeover (FP-11, whose
 * conformance line requires notifying administrators).
 *
 * Recipients are the users holding the federation.notifications
 * permission, so the roles matrix decides who is on call. Everything
 * else federation does stays in the activity log.
 *
 * // federation-protocol.md §Identity and Trust Model
 */
class FederationNotifier
{
    /**
     * An unknown node contacted this node and was stored as a
     * provisional partner. Fired only for unsolicited inbound
     * introductions — an operator-initiated add needs no email telling
     * the operator what they just did.
     */
    public function partnerFirstContact(FederationPartner $partner): void
    {
        Notification::send($this->recipients(), new PartnerFirstContact($partner));
    }

    /**
     * A partner already known here sent a partnership request. First
     * contact is covered by partnerFirstContact (the request's message
     * reaches that mail through the queue); this is for every later
     * request, so it is never invisible.
     */
    public function partnershipRequested(FederationPartner $partner): void
    {
        Notification::send($this->recipients(), new PartnershipRequested($partner));
    }

    public function partnerNodeUuidChanged(FederationPartner $partner): void
    {
        Notification::send($this->recipients(), new PartnerNodeUuidChanged($partner));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        // Inbound federation requests must not fatal on an installation
        // that has not seeded its roles yet — no permission row simply
        // means nobody has subscribed.
        if (! Permission::query()->where('name', PermissionEnum::ReceiveFederationNotifications->value)->exists()) {
            return new Collection;
        }

        return User::permission(PermissionEnum::ReceiveFederationNotifications->value)->get();
    }
}
