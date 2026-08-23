<?php

namespace App\Console\Commands;

use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Services\Federation\SyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Polls partners' updated_since endpoints. Scheduled hourly, which keeps
 * this node comfortably inside the 24-hour update obligation for copies
 * (ID-7); failed partners back off exponentially (capped at 24 hours).
 *
 * // api-design.md §Listings
 */
class OpenYachtSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:sync
        {--partner= : Sync a single partner by domain}
        {--force : Ignore the failure backoff}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync listings from federation partners';

    public function handle(SyncService $sync): int
    {
        $partners = FederationPartner::query()
            ->where('trust_level', '!=', TrustLevel::Blocked)
            ->when($this->option('partner'), fn ($query, $domain) => $query->where('domain', $domain))
            ->get();

        if ($partners->isEmpty()) {
            $this->info('No partners to sync.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($partners as $partner) {
            if (! $this->option('force') && ! $sync->isDue($partner)) {
                $this->line("{$partner->domain}: backing off ({$partner->consecutive_failures} consecutive failures)");

                continue;
            }

            try {
                $result = $sync->sync($partner);

                $this->info("{$partner->domain}: {$result->created} created, {$result->updated} updated, {$result->tombstoned} tombstoned");
            } catch (Throwable $exception) {
                $failures++;
                $this->error("{$partner->domain}: sync failed — {$exception->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
