---
paths:
    - 'database/migrations/**'
---

# Migrations

## Wire-changing migrations must stamp federation_updated_at, scoped to affected listings

A migration that changes what a listing serves on the wire (new field, backfill, serialization change) MUST set `federation_updated_at` for the affected listings — otherwise `updated_since` consumers never re-fetch and mix schema generations forever (findings issue 5; spec follow-up 8, standing rule shared with the WP plugin's migration runner). Scope the touch to exactly the listings whose serialization changed, not the whole catalog, and do any backfill (e.g. generating media conversions) BEFORE the stamp so consumers never re-fetch a half-migrated form. Precedent: 2026_08_26_201421_backfill_gallery_thumbnails_and_stamp_federation_updated_at.php.

## Name composite indexes explicitly — MySQL caps identifiers at 64 chars

Laravel's auto-generated index names (`table_col1_col2_unique`) blow past MySQL's 64-character identifier limit on tables with long names — `partner_group_members_partner_group_id_federation_partner_id_unique` (68 chars) broke the tests-mysql lane on 2026-08-27 while SQLite accepted it silently. Give composite uniques/indexes explicit short names: `$table->unique([...], 'partner_group_members_unique')`. SQLite never surfaces this; only the MySQL lane does — another reason both lanes gate every deploy.
