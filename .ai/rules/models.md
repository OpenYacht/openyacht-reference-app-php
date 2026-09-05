---
paths:
    - 'app/Models/*.php'
    - 'app/Models/**'
---

# Models

## Sale and charter listings never share a table — the table is the type

Own listings are per-type aggregates: `sale_yachts` (SaleYacht) and `charter_yachts` (CharterYacht), both hanging off the shared `vessels` table and sharing identity/lifecycle/media via the `FederatedListing` concern. There is deliberately no `type` column and no shared listings table: the wire `type` comes from the model class, which makes the type immutable the same way the canonical UUID is (ID-1). A vessel both for sale and for charter is two listings with two UUIDs. Never mix the types in one list, screen, or table — separate routes/pages, not filter tabs (Rob's standing call, mirrored across all OpenYacht implementations). Charter carries no price columns (wire `listing.price` is null by design); its pricing is the `rates` JSON block, gated under the `pricing` field group; crew is emitted only while `crew_attested_at` is set (LS-15).

## Change notifications: hook created/updated, never saved; one ping per batch

Outbound change notifications (ChangeNotifier) fire once per applied batch — end of a sync cycle (OpenYachtSync), a manual import/removal, an own-listing edit — never per listing, and are debounced by a cache cooldown. Model hooks live on created/updated, NOT saved: Eloquent fires saved even for a no-op save (e.g. update() with only non-fillable attrs) with wasChanged()/getChanges() still holding the PREVIOUS save's change set, which caused phantom notifications. Audience-only changes stay quiet (federation-facing, not public-facing), mirroring the federation_updated_at skip.
