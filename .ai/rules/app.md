---
paths:
    - 'app/**/*.php'
---

# App

## Numeric JSON-path comparisons need `? * 1` bindings

PDO binds PHP floats as strings, and SQLite never coerces TEXT against the REAL that json_extract() returns — so `->where('payload->vessel->loa_m', '>=', 30.0)` silently matches nothing on SQLite (integers work; floats don't). Use the `FiltersListings::whereJsonNumeric()` helper (app/Http/Controllers/Concerns) for numeric JSON-path comparisons — it binds through `? * 1`, restoring numeric affinity portably across SQLite, MySQL, and MariaDB. Regression test: SyncTest 'the synced listing filters read the copy payload, floats included'. Broader guard: the whole suite also runs against MySQL (`vendor/bin/pest --configuration=phpunit.mysql.xml`, enforced in CI) so any other engine divergence fails a test instead of shipping.

## MySQL JSON columns re-order object keys — never strict-compare stored JSON arrays

MySQL's JSON column type stores objects in its own key order (sorted binary JSON), so an array cast round-trip returns keys in a different order than the PHP code built them; SQLite (text JSON) preserves order. A strict `===` between a freshly built array and a stored-JSON attribute therefore fails on MySQL only — this silently reset ID-9 conflict reviews on every sync (fixed in SyncService::reconcileIdentityConflicts, 2026-08-27). Compare with `==` (key-order-insensitive, list order still significant) or a recursive ksort+json_encode; never `===`.

## Money: wire strings, decimal projections, query-time ECB conversion, no silent fallbacks

The spec's money_amount is a string for wire reproducibility — that binds listing_copies.payload only. Local projection/authoring columns (sale_yachts.price_amount, imported_yachts.price_amount, price_histories.amount) are DECIMAL(15,2); the decimal:2 cast means serialized amounts carry two decimals ("1500000.00"). Cross-currency price search converts the SEARCH BOUNDS per inventory currency at query time (SearchableByPrice trait) against daily ECB rates (exchange_rates table, EUR base, SyncExchangeRates job) — never rewrite stored prices, never add a fallback rate table, and a target currency without a fetched rate is a 422, never silently unconverted results (Rob's standing call, 2026-08-29).
