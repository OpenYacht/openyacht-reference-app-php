---
paths:
  - 'app/Services/Federation/**'
---

# Services Federation

## Visibility is answered in exactly two places — keep them mirrored
A listing's per-partner visibility (audience everyone/selected/none; selected = union of individually selected partners and members of selected groups) is decided by `SharingService::isVisibleTo()` in PHP and by the one SQL fragment in `ListingsController::visibleSql()` — nowhere else, and the two MUST stay mirrored (shared invariant with the WP plugin's implementation). Never stamp `federation_updated_at` for sharing changes: the `FederatedListing` updating hook deliberately skips audience-only updates, and per-partner deltas travel through append-only `visibility_events` (visible/hidden/refreshed) keyed by listing UUID — row ids collide across the two listing tables, UUIDs never do. The feed's effective timestamp is a portable CASE (GREATEST does not exist on SQLite).
