<?php

namespace App\Console\Commands;

use App\Jobs\ImportYachtMedia;
use App\Models\ImportedYacht;
use App\Services\Federation\ImportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Re-queues the media import for every imported yacht whose renditions
 * are missing or no longer match its copy's source media.
 *
 * The import itself is queued once, when the copy is imported or its
 * media changes on sync (ID-7). A job that dies — worker killed mid-run,
 * tries exhausted, queue lost — would otherwise leave the yacht without
 * images indefinitely, since nothing else looks at it again. This hourly
 * sweep is the safety net; a yacht whose import is still pending is
 * skipped because the job is unique per yacht.
 *
 * // listing-schema.md §Media
 */
class OpenYachtSyncMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:sync-media';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue media imports for imported yachts whose renditions are missing or out of date';

    public function handle(ImportService $imports): int
    {
        $queued = 0;

        ImportedYacht::query()
            ->with(['copy', 'media'])
            ->chunkById(100, function (Collection $yachts) use ($imports, &$queued): void {
                /** @var Collection<int, ImportedYacht> $yachts */
                foreach ($yachts as $yacht) {
                    if ($imports->needsMediaImport($yacht)) {
                        ImportYachtMedia::dispatch($yacht);
                        $queued++;
                    }
                }
            });

        $this->info("{$queued} imported ".Str::plural('yacht', $queued).' with missing or outdated media; imports queued.');

        return self::SUCCESS;
    }
}
