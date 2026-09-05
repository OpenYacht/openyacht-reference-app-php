---
paths:
    - 'app/Http/Controllers/Federation/**'
---

# Federation

## The /listings feed unions both tables on a (federation_updated_at, uuid) keyset

`ListingsController::index` runs identical constraints against `sale_yachts` and `charter_yachts`, fetches pageSize+1 from each, and merge-sorts in memory on `(federation_updated_at, uuid)` — the keyset `ListingCursor` encodes. The uuid (not row id) is the tie-breaker because row ids collide across the two tables while canonical UUIDs never do, and uuid ASCII ordering is collation-stable across SQLite/MySQL/MariaDB. Keep any new feed constraint inside the shared `$fetch` closure so both types stay in one consistent sequence; per-uuid lookups (`show`, internal API) resolve SaleYacht first, then CharterYacht.
