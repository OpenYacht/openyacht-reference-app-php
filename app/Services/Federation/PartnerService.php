<?php

namespace App\Services\Federation;

use App\Enums\FederationErrorCode;
use App\Enums\IntroductionOutcome;
use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Models\User;
use App\Models\VisibilityEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Partner lifecycle: first contact (TOFU), the outbound partnership
 * request that introduces this node to the other side, key refresh with
 * reinstall detection, and the human approve/block decisions.
 *
 * // federation-protocol.md §Identity and Trust Model, §Partner Lifecycle
 */
class PartnerService
{
    public function __construct(
        private WellKnownClient $wellKnown,
        private FederationNotifier $notifier,
        private SignedClient $client,
    ) {}

    /**
     * Add a partner by domain. Trust on first use: the keys and node UUID
     * the domain serves now become the stored identity; the partner starts
     * as provisional until a human approves (FP-13).
     */
    public function add(string $domain): FederationPartner
    {
        $domain = strtolower(trim($domain));

        // Defence in depth behind the form request and the inbound
        // middleware: a node partnered with itself syncs its own listings
        // back as partner copies.
        if ($domain === strtolower((string) config('openyacht.domain'))) {
            throw new InvalidArgumentException(__('federation.domain_is_self'));
        }

        $document = $this->wellKnown->fetch($domain);

        $partner = FederationPartner::create([
            'domain' => $domain,
            'node_name' => data_get($document, 'node.name'),
            'node_uuid' => data_get($document, 'node.uuid'),
            'keys_json' => $document['keys'],
            'keys_fetched_at' => now(),
            'trust_level' => TrustLevel::Provisional,
            'last_ok_at' => now(),
        ]);

        // Arm the trust-on-first-use pin to the key the partner signs
        // with now (FP-12). Until an administrator confirms a rotation,
        // this is the only key the verifier will accept — so a later
        // silent key swap by whoever controls the partner's well-known
        // document is rejected, not trusted. Computed from the stored
        // keys via the model so there is one source of truth.
        $partner->update(['pinned_key_id' => $partner->currentSigningKeyId()]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $domain])
            ->event('partner_added')
            ->log("Partner {$domain} added (provisional)");

        return $partner;
    }

    /**
     * Introduce this node to a partner with a signed partnership request
     * (federation-protocol.md §Partner Lifecycle step 1): their node
     * verifies the signature, stores this node as provisional and
     * notifies its administrators — the request is what puts a "who are
     * you and why" in front of the person who approves it.
     *
     * Nodes without the partners/request endpoint answer 404/405 from
     * their router before any signature check, so nothing is registered
     * over there; every conformant node authenticates a signed listings
     * read, which is why that is the fallback. The partner row is saved
     * before the attempt, so every outcome is reported, never thrown.
     *
     * Both fields always go on the wire: at least one receiver rejects a
     * body missing either as a validation error after registering the
     * sender, which would read as a failed introduction.
     */
    public function introduce(FederationPartner $partner, ?string $message = null, ?string $contactEmail = null): PartnerIntroduction
    {
        $message = trim((string) $message) !== ''
            ? trim((string) $message)
            : __('federation.introduction.default_message', ['name' => config('openyacht.node_name')]);

        $contactEmail = trim((string) $contactEmail) !== ''
            ? trim((string) $contactEmail)
            : (string) config('mail.from.address');

        $introduction = $this->attempt($partner, $message, $contactEmail);

        if ($introduction->outcome->reachedPartner()) {
            $partner->update(['request_sent_at' => now()]);
        }

        activity('federation')
            ->performedOn($partner)
            ->withProperties([
                'domain' => $partner->domain,
                'outcome' => $introduction->outcome->value,
                'message' => $message,
                'contact_email' => $contactEmail,
                'detail' => $introduction->message,
            ])
            ->event('partner_request_sent')
            ->log("Partnership request to {$partner->domain}: {$introduction->outcome->value}");

        return $introduction;
    }

    /**
     * The wire round trip. Every failure mode short of a bug is reported
     * as an outcome here so the caller never has to catch.
     */
    private function attempt(FederationPartner $partner, string $message, string $contactEmail): PartnerIntroduction
    {
        try {
            $response = $this->client->post($partner, '/openyacht/v1/partners/request', [
                'message' => $message,
                'contact_email' => $contactEmail,
            ]);

            $probed = in_array($response->status(), [404, 405], true);

            if ($probed) {
                $response = $this->client->get($partner, '/openyacht/v1/listings?page_size=1');
            }
        } catch (ConnectionException $exception) {
            return $this->introductionFailed($partner, __('federation.introduction.unreachable', [
                'domain' => $partner->domain,
                'reason' => $exception->getMessage(),
            ]));
        } catch (RuntimeException $exception) {
            // BlockedOutboundHost (the outbound guard refused the host) or
            // the Signer's "no active key" — both for the operator to fix.
            return $this->introductionFailed($partner, $exception->getMessage());
        }

        return $this->interpret($partner, $response, $probed);
    }

    /**
     * Read the partner's answer. A 2xx from the request endpoint means it
     * was registered, and its trust_level says whether a human there
     * still has to approve; a 2xx from the listings probe means they are
     * already serving this node listings, which only a verified partner
     * gets (FP-13). A 403 PARTNER_PROVISIONAL from either is "registered,
     * awaiting a human".
     */
    private function interpret(FederationPartner $partner, Response $response, bool $probed): PartnerIntroduction
    {
        $code = data_get($response->json(), 'error.code');

        if ($response->successful()) {
            return $probed || data_get($response->json(), 'trust_level') === TrustLevel::Verified->value
                ? new PartnerIntroduction(IntroductionOutcome::Accepted, __('federation.introduction.accepted', ['domain' => $partner->domain]))
                : new PartnerIntroduction(IntroductionOutcome::Delivered, __('federation.introduction.delivered', ['domain' => $partner->domain]));
        }

        if ($response->status() === 403 && $code === FederationErrorCode::PartnerProvisional->value) {
            return new PartnerIntroduction(IntroductionOutcome::Delivered, __('federation.introduction.delivered', ['domain' => $partner->domain]));
        }

        if ($response->status() === 403 && $code === FederationErrorCode::PartnerBlocked->value) {
            return new PartnerIntroduction(IntroductionOutcome::Blocked, __('federation.introduction.blocked', ['domain' => $partner->domain]));
        }

        return $this->introductionFailed($partner, __('federation.introduction.unexpected_answer', [
            'domain' => $partner->domain,
            'status' => $response->status(),
            'code' => is_string($code) ? $code : __('federation.introduction.no_error_code'),
        ]));
    }

    private function introductionFailed(FederationPartner $partner, string $reason): PartnerIntroduction
    {
        return new PartnerIntroduction(IntroductionOutcome::Failed, __('federation.introduction.failed', [
            'domain' => $partner->domain,
            'reason' => $reason,
        ]));
    }

    /**
     * Refetch the partner's well-known document and update the cached
     * keys. A changed node UUID means the domain now hosts a different
     * installation: downgrade to provisional and notify administrators
     * (FP-11).
     *
     * Called with $pinConfirmedBy — the explicit administrator action —
     * this refresh is also the FP-12 pin confirmation: the pin moves to
     * the partner's current signing key, so a pinned partner that
     * rotated is accepted again. The verifier's automatic recovery
     * refetch calls without it and never touches the pin, and a changed
     * node UUID always leaves the pin where it was (FP-11 wins).
     */
    public function refreshKeys(FederationPartner $partner, ?User $pinConfirmedBy = null): FederationPartner
    {
        $document = $this->wellKnown->fetch($partner->domain);
        $freshUuid = data_get($document, 'node.uuid');
        $previousUuid = $partner->node_uuid;

        if ($previousUuid !== null && $freshUuid !== $previousUuid) {
            $partner->update([
                'node_name' => data_get($document, 'node.name'),
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

            $this->notifier->partnerNodeUuidChanged($partner->refresh());

            return $partner;
        }

        $partner->update([
            'node_name' => data_get($document, 'node.name'),
            'keys_json' => $document['keys'],
            'keys_fetched_at' => now(),
        ]);

        if ($pinConfirmedBy !== null) {
            $this->confirmPinnedKey($partner, $pinConfirmedBy);
        }

        return $partner->refresh();
    }

    /**
     * The FP-12 confirmation: move the pin to the partner's current
     * signing key and log the re-pin. Only the explicit administrator
     * refresh reaches this — an attacker who can alter the well-known
     * document must never be able to move the pin through the verifier's
     * automatic refetch.
     */
    private function confirmPinnedKey(FederationPartner $partner, User $confirmedBy): void
    {
        $currentKeyId = $partner->currentSigningKeyId();

        if ($partner->pinned_key_id === null || $currentKeyId === null || $currentKeyId === $partner->pinned_key_id) {
            return;
        }

        $previousKeyId = $partner->pinned_key_id;
        $partner->update(['pinned_key_id' => $currentKeyId]);

        activity('federation')
            ->causedBy($confirmedBy)
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain, 'previous_key_id' => $previousKeyId, 'pinned_key_id' => $currentKeyId])
            ->event('partner_key_repinned')
            ->log("Pinned key for {$partner->domain} moved to {$currentKeyId} after administrator confirmation");
    }

    /**
     * Human approval: the partner becomes verified (FP-13).
     */
    public function approve(FederationPartner $partner, User $approvedBy): FederationPartner
    {
        $partner->update([
            'trust_level' => TrustLevel::Verified,
            'approved_by_user_id' => $approvedBy->id,
            // Establish the pin if a partner predates pin-arming at first
            // contact; approval is the human "I trust this partner" moment.
            'pinned_key_id' => $partner->pinned_key_id ?? $partner->currentSigningKeyId(),
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

    /**
     * Remove a partner nothing has been received from — the undo for a
     * mistaken add. Once copies exist the partner row is their
     * provenance anchor (ID-3) and the partnership ends by blocking, not
     * deletion (federation-protocol.md §Trust levels has no "removed"
     * state). The approve/block history survives in the activity log,
     * keyed by domain.
     */
    public function remove(FederationPartner $partner, User $removedBy): void
    {
        if (! $partner->isRemovable()) {
            throw new InvalidArgumentException(__('federation.partner_not_removable', ['domain' => $partner->domain]));
        }

        $domain = $partner->domain;

        activity('federation')
            ->causedBy($removedBy)
            ->performedOn($partner)
            ->withProperties(['domain' => $domain, 'trust_level' => $partner->trust_level->value])
            ->event('partner_removed')
            ->log("Partner {$domain} removed");

        // Audience and group rows cascade; the visibility log carries no
        // foreign key (append-only by design) so its rows go explicitly.
        VisibilityEvent::query()->where('federation_partner_id', $partner->id)->delete();

        $partner->delete();
    }
}
