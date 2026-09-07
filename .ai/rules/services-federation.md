---
paths:
    - 'app/Services/Federation/**'
    - app/Services/Federation/PartnerService.php
---

# Services Federation

## Visibility is answered in exactly two places — keep them mirrored

A listing's per-partner visibility (audience everyone/selected/none; the full rule is in the next note) is decided by `SharingService::isVisibleTo()` in PHP and by the one SQL fragment in `ListingsController::visibleSql()` — nowhere else, and the two MUST stay mirrored (shared invariant with the WP plugin's implementation). Never stamp `federation_updated_at` for sharing changes: the `FederatedListing` updating hook deliberately skips audience-only updates, and per-partner deltas travel through append-only `visibility_events` (visible/hidden/refreshed) keyed by listing UUID — row ids collide across the two listing tables, UUIDs never do. The feed's effective timestamp is a portable CASE (GREATEST does not exist on SQLite).

## Visibility rule includes partner sharing scope; pivots are additive

Since the curated-partners feature (2026-08-29), the mirrored visibility rule (SharingService::isVisibleTo + ListingsController::visibleSql — still the only two places) is: visible ⇔ audience != 'none' AND (explicit pivot match OR (audience = 'everyone' AND partner sharing_scope = standard)). Pivots are ADDITIVE: an explicit selection (direct row or group) grants under any non-none audience, not just 'selected' — it is how a curated partner receives anything at all. Consequences: setAudience keeps pivot rows for 'everyone' (and leaves them untouched for 'none' — hiding must not destroy a curated selection); the curated feed branch simply drops the everyone OR-arm (still exactly 2 bindings per occurrence); flipping a partner's sharing_scope emits visible/hidden events via SharingService::setSharingScope, never a federation_updated_at stamp. The WP plugin must mirror the same rule.

## Every partner add introduces this node; tests must fake partners/request and seed a signing key

Since 2026-09-07 every operator add (hand-typed form, node-directory add) calls PartnerService::introduce() after add(): a signed POST /openyacht/v1/partners/request with BOTH message and contact_email always populated (the WP plugin rejects a body missing either after registering the sender), falling back to a signed GET listings?page_size=1 on 404/405. Outcomes are reported (flash + partner_request_sent activity), never thrown; only delivered/accepted stamp request_sent_at. A 2xx from the listings probe counts as accepted, not delivered — only a verified partner is served listings (FP-13). Test trap: Http::fake([...]) with URL patterns lets unmatched URLs hit the real network, so any test that adds a partner must also fake `<domain>/openyacht/v1/partners/request` and create a FederationKey (otherwise the Signer's "no active key" turns the add into a failed introduction before any HTTP — passing, but not what you meant to test).

## Push subscriptions: derive from feed sources, apply state before emitting events, dedup against the copy

Push (api-design.md §Subscriptions, API-10/11) tracks no changes of its own: SubscriptionService derives deliveries from the same two feed sources (the federation_updated_at stamp via FederatedListing's saved hook, and VisibilityEvent::created) and DeliverSubscriptionChange derives the payload AT SEND TIME via SubscriptionService::payloadFor → ListingSerializer::feedItem, the single decision the polled feed also uses — never snapshot a payload at enqueue. Consequence for SharingService: every mutator must apply its state (pivots, audience, scope, membership) BEFORE creating visibility events, because the event hook may run the delivery synchronously; setAudience was reordered to apply-then-diff for this on 2026-09-07. Consumer dedup (SyncService::applyDelivery) is on (id, updated_at) against the stored copy's listing_updated_at + tombstoned state, deliberately NOT a receipts log: a re-shared listing legitimately arrives again with the same updated_at it had before its tombstone and must apply. Only verified partners register callbacks, and the inbox only accepts pushes from verified partners this node actually subscribed to (push_subscribed_at); subscribed partners still poll daily (SyncService::isDue) — a subscription never replaces updated_since.
