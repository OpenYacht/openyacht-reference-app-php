<?php

namespace App\Console\Commands;

use App\Services\ChangeNotifier;
use Illuminate\Console\Command;

/**
 * Manual/scheduled trigger for the outbound change notification — e.g.
 * to force a consumer rebuild after an operational change the automatic
 * hooks cannot see. Bypasses the debounce cooldown.
 */
class OpenYachtNotifyChange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:notify-change
        {--reason=manual : Reason string sent with the notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the outbound change notification to every active webhook endpoint';

    public function handle(ChangeNotifier $notifier): int
    {
        if (! $notifier->notify((string) $this->option('reason'), force: true)) {
            $this->warn('No active webhook endpoints are configured — add one under Webhooks in the admin.');

            return self::FAILURE;
        }

        $this->info('Change notification queued.');

        return self::SUCCESS;
    }
}
