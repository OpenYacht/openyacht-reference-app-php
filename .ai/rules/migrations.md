---
paths:
  - 'database/migrations/**'
---

# Migrations

## Wire-changing migrations must stamp federation_updated_at, scoped to affected listings
A migration that changes what a listing serves on the wire (new field, backfill, serialization change) MUST set `federation_updated_at` for the affected listings — otherwise `updated_since` consumers never re-fetch and mix schema generations forever (findings issue 5; spec follow-up 8, standing rule shared with the WP plugin's migration runner). Scope the touch to exactly the listings whose serialization changed, not the whole catalog, and do any backfill (e.g. generating media conversions) BEFORE the stamp so consumers never re-fetch a half-migrated form. Precedent: 2026_08_26_201421_backfill_gallery_thumbnails_and_stamp_federation_updated_at.php.
