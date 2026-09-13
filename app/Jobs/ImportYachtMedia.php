<?php

namespace App\Jobs;

use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use App\Services\Federation\OutboundUrlGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads an imported yacht's source images and generates local WebP
 * renditions: three widths for srcset on every image, plus cropped hero
 * variants of the profile image.
 *
 * The wire carries one large image per item; receivers derive their own
 * sizes (listing-schema.md §Media). Inbound media is untrusted (FP-14):
 * https only, the response must actually be an image, and the sha256 is
 * verified whenever the authority provided one.
 *
 * One job covers a whole yacht — a large gallery is dozens of downloads
 * and several renditions each, well past the worker's 60-second default
 * — so the job declares its own timeout. The queue's retry_after must
 * exceed it (see .env.example), or a still-running import is handed to
 * a second worker as a duplicate.
 */
class ImportYachtMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const MAX_DOWNLOAD_BYTES = 30 * 1024 * 1024;

    /**
     * Seconds a single yacht's import may run before the worker kills it.
     */
    public int $timeout = 900;

    /**
     * How long a dispatched job blocks a duplicate for the same yacht; a
     * job that dies without releasing its lock frees up after this.
     */
    public int $uniqueFor = 960;

    /**
     * Imports removed before the queue catches up are simply skipped.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    public function __construct(public ImportedYacht $yacht) {}

    /**
     * One pending import per yacht: a second dispatch while one is queued
     * is a no-op, since the queued job reads the copy's current payload
     * when it runs.
     */
    public function uniqueId(): string
    {
        return (string) $this->yacht->id;
    }

    public function handle(): void
    {
        $yacht = $this->yacht->fresh(['copy']);

        if ($yacht === null) {
            return;
        }

        $disk = config('openyacht.media.disk');
        $directory = "imported/{$yacht->id}";

        // Idempotent re-import: clear previous renditions first.
        Storage::disk($disk)->deleteDirectory($directory);
        $yacht->media()->delete();

        foreach (self::sourceImages($yacht->copy->payload ?? []) as $index => $image) {
            try {
                $this->importImage($yacht, $image, $index, $disk, $directory);
            } catch (Throwable $exception) {
                Log::warning('openyacht.media_import_failed', [
                    'imported_yacht_id' => $yacht->id,
                    'url' => $image['url'],
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        // The import may have been removed while media was processing;
        // don't leave orphaned files behind.
        if ($yacht->fresh() === null) {
            Storage::disk($disk)->deleteDirectory($directory);

            return;
        }

        $yacht->update(['media_synced_at' => now()]);
    }

    /**
     * The importable source images of a wire payload: the profile image
     * first, then the gallery in sort order.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, array{kind: string, url: string, sha256: string|null, caption: string|null, sort: int}>
     */
    public static function sourceImages(array $payload): Collection
    {
        /** @var list<array{kind: string, url: string, sha256: string|null, caption: string|null, sort: int}> $images */
        $images = [];

        $profile = data_get($payload, 'media.profile');

        if (is_array($profile) && is_string($profile['url'] ?? null)) {
            $images[] = [
                'kind' => 'profile',
                'url' => $profile['url'],
                'sha256' => is_string($profile['sha256'] ?? null) ? $profile['sha256'] : null,
                'caption' => is_string($profile['caption'] ?? null) ? $profile['caption'] : null,
                'sort' => 0,
            ];
        }

        $gallery = data_get($payload, 'media.gallery');

        foreach (collect(is_array($gallery) ? $gallery : [])->sortBy('sort')->values() as $index => $item) {
            if (! is_array($item) || ! is_string($item['url'] ?? null)) {
                continue;
            }

            $images[] = [
                'kind' => 'gallery',
                'url' => $item['url'],
                'sha256' => is_string($item['sha256'] ?? null) ? $item['sha256'] : null,
                'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                'sort' => is_int($item['sort'] ?? null) ? $item['sort'] : $index + 1,
            ];
        }

        return collect($images);
    }

    /**
     * @param  array{kind: string, url: string, sha256: string|null, caption: string|null, sort: int}  $image
     */
    private function importImage(ImportedYacht $yacht, array $image, int $index, string $disk, string $directory): void
    {
        $bytes = $this->download($image['url'], $image['sha256']);

        $quality = max(1, min(100, (int) config('openyacht.media.quality')));
        $renditions = [];
        $producedWidths = [];

        foreach (config('openyacht.media.widths') as $width) {
            $rendition = Image::fromBytes($bytes)
                ->orient()
                ->scale(width: $width)
                ->toWebp()
                ->quality($quality);

            // scale() never upscales; skip duplicate widths from small sources.
            if (in_array($rendition->width(), $producedWidths, true)) {
                continue;
            }

            $producedWidths[] = $rendition->width();
            $name = "{$image['kind']}-{$index}-{$rendition->width()}.webp";
            $rendition->storePubliclyAs($directory, $name, $disk);

            $renditions["w{$rendition->width()}"] = [
                'path' => "{$directory}/{$name}",
                'width' => $rendition->width(),
                'height' => $rendition->height(),
            ];
        }

        if ($image['kind'] === 'profile') {
            $ratio = (float) config('openyacht.media.profile_crop_ratio');

            foreach (config('openyacht.media.widths') as $width) {
                $height = max(1, (int) round($width / $ratio));

                $rendition = Image::fromBytes($bytes)
                    ->orient()
                    ->cover($width, $height)
                    ->toWebp()
                    ->quality($quality);

                $name = "profile-{$index}-crop-{$width}.webp";
                $rendition->storePubliclyAs($directory, $name, $disk);

                $renditions["crop_{$width}"] = [
                    'path' => "{$directory}/{$name}",
                    'width' => $rendition->width(),
                    'height' => $rendition->height(),
                ];
            }
        }

        if ($renditions === []) {
            return;
        }

        ImportedMedia::create([
            'imported_yacht_id' => $yacht->id,
            'kind' => $image['kind'],
            'source_url' => $image['url'],
            'source_sha256' => $image['sha256'],
            'caption' => $image['caption'],
            'sort' => $image['sort'],
            'renditions' => $renditions,
        ]);
    }

    /**
     * Download one source image, enforcing the untrusted-input rules
     * (FP-14) and verifying the content hash when provided.
     */
    private function download(string $url, ?string $expectedSha256): string
    {
        // The media URL comes straight from the partner's payload, so it
        // is untrusted: require a plain https URL to a public host (SSRF)
        // and never follow a redirect that could bounce to a private host
        // or plain HTTP (FP-14). A blocked URL is caught per-image by the
        // caller and the image is skipped.
        app(OutboundUrlGuard::class)->assertPublicHttpsUrl($url);

        $response = Http::timeout(60)->withoutRedirecting()->get($url)->throw();

        if (! Str::startsWith($response->header('Content-Type'), 'image/')) {
            throw new \RuntimeException('The media URL did not return an image.');
        }

        $bytes = $response->body();

        if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
            throw new \RuntimeException('The media file exceeds the download size limit.');
        }

        if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), hash('sha256', $bytes))) {
            throw new \RuntimeException('The media file does not match its published sha256.');
        }

        return $bytes;
    }
}
