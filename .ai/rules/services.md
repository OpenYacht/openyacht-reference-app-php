---
paths:
    - app/Services/ChangeNotifier.php
---

# Services

## Scheduled webhook pings are a floor measured from the last ping of any kind, decided in PHP

A webhook endpoint's schedule_interval_minutes is a freshness floor, not a cadence: every dispatch (change, test, scheduled) goes through ChangeNotifier::dispatch(), which stamps last_notified_at, and the hourly openyacht:notify-scheduled pings only endpoints whose interval has elapsed since that stamp (minus SCHEDULE_TOLERANCE_MINUTES so a tick seconds short doesn't slip an hour). A busy node therefore never adds scheduled builds on top of change notifications — the schedule exists for data the node cannot see change (exchange rates, partners' well-known documents). Never route a dispatch around dispatch(), and never add a fixed-time cron in a consumer's repo for this (the demo site's hourly GitHub Action rebuilt on nothing and was removed 2026-09-06). The due check is WebhookEndpoint::isScheduledPingDue() in PHP over the few active endpoints: per-row intervals need date arithmetic that is not portable across SQLite/MySQL/MariaDB, so do not move it into a SQL scope.
