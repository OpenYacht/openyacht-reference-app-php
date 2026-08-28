<?php

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

// Enforce the activity-log retention window daily (0 days = keep forever).
Schedule::command('openyacht:prune-activity-log')->daily();
