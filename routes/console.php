<?php

use App\Jobs\SyncExchangeRates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly polling keeps copies comfortably inside the 24-hour update
// obligation (ID-7); per-partner failure backoff is handled inside the
// command. // api-design.md §Listings
Schedule::command('openyacht:sync')->hourly();

// Safety net for media imports whose job died (worker killed, tries
// exhausted): re-queue anything still missing or out of date. Pending
// imports are skipped — the job is unique per yacht.
Schedule::command('openyacht:sync-media')->hourly();

// Per-endpoint "freshness floor" for webhook consumers: ping any endpoint
// whose configured interval has elapsed since its last notification. The
// intervals are in whole hours, so an hourly tick is exact enough.
Schedule::command('openyacht:notify-scheduled')->hourly();

// Enforce the activity-log retention window daily (0 days = keep forever).
Schedule::command('openyacht:prune-activity-log')->daily();

// ECB reference rates publish once per working day (~16:00 CET); a daily
// evening fetch keeps the data API's cross-currency price search current.
Schedule::job(new SyncExchangeRates)->dailyAt('17:00');
