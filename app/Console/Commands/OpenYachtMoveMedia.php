<?php

namespace App\Console\Commands;

use App\Models\CharterYacht;
use App\Models\SaleYacht;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * Moves the node's own listing media to another configured disk.
 *
 * MEDIA_DISK only decides where new uploads go: every media row records
 * the disk it was written to, so pointing the variable at object storage
 * leaves existing files — and the URLs partners were given — on the old
 * disk. This copies each row's original, conversions and responsive
 * images to the target disk under the same paths (medialibrary's paths
 * do not depend on the disk), then repoints the rows.
 *
 * Own media URLs are wire data, so the move is a wire change: listings
 * still being served get federation_updated_at stamped, after the files
 * are in place, so a consumer's updated_since poll re-fetches them and
 * never sees a half-moved listing. The stamp is an ordinary model save,
 * so the listing's own hooks notify webhook consumers and push
 * subscribers. Terminal listings serve tombstones and are left alone.
 *
 * // listing-schema.md §Media
 */
class OpenYachtMoveMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:move-media
        {disk : The configured filesystem disk to move own-listing media to}
        {--prune : Delete each file from its old disk once the copy is confirmed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Move the node's own listing media to another disk and re-announce the affected listings";

    public function handle(): int
    {
        $target = (string) $this->argument('disk');

        if (! is_array(config("filesystems.disks.{$target}"))) {
            $this->error("Disk [{$target}] is not configured in config/filesystems.php.");

            return self::FAILURE;
        }

        $moved = 0;
        $listings = [];

        Media::query()
            ->where(fn ($query) => $query
                ->where('disk', '!=', $target)
                ->orWhere('conversions_disk', '!=', $target))
            ->with('model')
            ->chunkById(50, function (Collection $chunk) use ($target, &$moved, &$listings): void {
                /** @var Collection<int, Media> $chunk */
                foreach ($chunk as $media) {
                    $this->move($media, $target);
                    $moved++;

                    if ($media->model instanceof Model) {
                        $listings[$media->model::class.':'.$media->model->getKey()] = $media->model;
                    }
                }
            });

        $stamped = 0;

        foreach ($listings as $listing) {
            if (($listing instanceof SaleYacht || $listing instanceof CharterYacht) && ! $listing->status->isTerminal()) {
                $listing->touch('federation_updated_at');
                $stamped++;
            }
        }

        $this->info("{$moved} media ".Str::plural('file', $moved)." moved to [{$target}]; {$stamped} ".Str::plural('listing', $stamped).' re-announced.');

        if ($moved > 0 && $stamped === 0) {
            $this->line('No served listing changed on the wire; run openyacht:notify-change if a consumer builds from these files.');
        }

        return self::SUCCESS;
    }

    /**
     * Copy one row's files to the target disk, then repoint the row. The
     * original and its derivatives may already sit on different disks.
     */
    private function move(Media $media, string $target): void
    {
        $paths = PathGeneratorFactory::create($media);

        $sources = [
            [$media->disk, $paths->getPath($media)],
            [$media->conversions_disk ?: $media->disk, $paths->getPathForConversions($media)],
            [$media->conversions_disk ?: $media->disk, $paths->getPathForResponsiveImages($media)],
        ];

        $destination = Storage::disk($target);

        foreach (array_unique($sources, SORT_REGULAR) as [$disk, $directory]) {
            if ($disk === $target) {
                continue;
            }

            $source = Storage::disk($disk);

            foreach ($source->files($directory) as $path) {
                $this->copy($source, $destination, $path);

                if ($this->option('prune')) {
                    $source->delete($path);
                }
            }
        }

        $media->forceFill(['disk' => $target, 'conversions_disk' => $target])->save();
    }

    private function copy(Filesystem $source, Filesystem $destination, string $path): void
    {
        $stream = $source->readStream($path);

        if ($stream === null) {
            throw new \RuntimeException("Could not read [{$path}] from the source disk.");
        }

        $destination->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $destination->exists($path)) {
            throw new \RuntimeException("Copy of [{$path}] did not arrive on the target disk.");
        }
    }
}
