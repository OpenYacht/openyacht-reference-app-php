<?php

namespace App\Console\Commands;

use App\Services\ActivityLogPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PruneActivityLog extends Command
{
    protected $signature = 'openyacht:prune-activity-log';

    protected $description = 'Delete activity-log entries older than the configured retention window';

    public function handle(ActivityLogPruner $pruner): int
    {
        $days = $pruner->retentionDays();

        if ($days <= 0) {
            $this->info('Activity-log retention is set to keep forever; nothing pruned.');

            return self::SUCCESS;
        }

        $deleted = $pruner->prune();

        $this->info("Pruned {$deleted} activity-log ".Str::plural('entry', $deleted)." older than {$days} days.");

        return self::SUCCESS;
    }
}
