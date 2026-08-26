---
paths:
  - 'app/**/*.php'
---

# App

## Numeric JSON-path comparisons need `? * 1` bindings
PDO binds PHP floats as strings, and SQLite never coerces TEXT against the REAL that json_extract() returns — so `->where('payload->vessel->loa_m', '>=', 30.0)` silently matches nothing on SQLite (integers work; floats don't). Use the `FiltersListings::whereJsonNumeric()` helper (app/Http/Controllers/Concerns) for numeric JSON-path comparisons — it binds through `? * 1`, restoring numeric affinity portably across SQLite, MySQL, and MariaDB. Regression test: SyncTest 'the synced listing filters read the copy payload, floats included'. Broader guard: the whole suite also runs against MySQL (`php artisan test --configuration=phpunit.mysql.xml`, enforced in CI) so any other engine divergence fails a test instead of shipping.

## MySQL JSON columns re-order object keys — never strict-compare stored JSON arrays
MySQL's JSON column type stores objects in its own key order (sorted binary JSON), so an array cast round-trip returns keys in a different order than the PHP code built them; SQLite (text JSON) preserves order. A strict `===` between a freshly built array and a stored-JSON attribute therefore fails on MySQL only — this silently reset ID-9 conflict reviews on every sync (fixed in SyncService::reconcileIdentityConflicts, 2026-08-27). Compare with `==` (key-order-insensitive, list order still significant) or a recursive ksort+json_encode; never `===`.
