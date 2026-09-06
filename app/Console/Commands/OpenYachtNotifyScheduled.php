<?php

namespace App\Console\Commands;

use App\Services\ChangeNotifier;
use Illuminate\Console\Command;

/**
 * Hourly scheduler entry point for the per-endpoint "freshness floor":
 * pings every active webhook endpoint whose scheduled interval has
 * elapsed since its last notification of any kind. The decision lives
 * in ChangeNotifier; this only reports what it did.
 */
class OpenYachtNotifyScheduled extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:notify-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the scheduled change notification to every webhook endpoint whose interval has elapsed';

    public function handle(ChangeNotifier $notifier): int
    {
        $queued = $notifier->notifyScheduled();

        $this->info($queued === 0 ? 'No scheduled notifications are due.' : "Scheduled notification queued to {$queued} endpoint(s).");

        return self::SUCCESS;
    }
}
