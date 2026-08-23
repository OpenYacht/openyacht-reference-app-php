---
paths:
  - 'app/**/*.php'
---

# App

## Numeric JSON-path comparisons need `? * 1` bindings
PDO binds PHP floats as strings, and SQLite never coerces TEXT against the REAL that json_extract() returns — so `->where('payload->vessel->loa_m', '>=', 30.0)` silently matches nothing on SQLite (integers work; floats don't). Use the `FiltersListings::whereJsonNumeric()` helper (app/Http/Controllers/Concerns) for numeric JSON-path comparisons — it binds through `? * 1`, restoring numeric affinity portably across SQLite, MySQL, and MariaDB. Regression test: SyncTest 'the synced listing filters read the copy payload, floats included'. Broader guard: the whole suite also runs against MySQL (`php artisan test --configuration=phpunit.mysql.xml`, enforced in CI) so any other engine divergence fails a test instead of shipping.
