---
paths:
  - 'app/Services/Federation/**'
---

# Services Federation

## Visibility is answered in exactly two places — keep them mirrored
A listing's per-partner visibility (audience everyone/selected/none; the full rule is in the next note) is decided by `SharingService::isVisibleTo()` in PHP and by the one SQL fragment in `ListingsController::visibleSql()` — nowhere else, and the two MUST stay mirrored (shared invariant with the WP plugin's implementation). Never stamp `federation_updated_at` for sharing changes: the `FederatedListing` updating hook deliberately skips audience-only updates, and per-partner deltas travel through append-only `visibility_events` (visible/hidden/refreshed) keyed by listing UUID — row ids collide across the two listing tables, UUIDs never do. The feed's effective timestamp is a portable CASE (GREATEST does not exist on SQLite).

## Visibility rule includes partner sharing scope; pivots are additive
Since the curated-partners feature (2026-08-29), the mirrored visibility rule (SharingService::isVisibleTo + ListingsController::visibleSql — still the only two places) is: visible ⇔ audience != 'none' AND (explicit pivot match OR (audience = 'everyone' AND partner sharing_scope = standard)). Pivots are ADDITIVE: an explicit selection (direct row or group) grants under any non-none audience, not just 'selected' — it is how a curated partner receives anything at all. Consequences: setAudience keeps pivot rows for 'everyone' (and leaves them untouched for 'none' — hiding must not destroy a curated selection); the curated feed branch simply drops the everyone OR-arm (still exactly 2 bindings per occurrence); flipping a partner's sharing_scope emits visible/hidden events via SharingService::setSharingScope, never a federation_updated_at stamp. The WP plugin must mirror the same rule.
