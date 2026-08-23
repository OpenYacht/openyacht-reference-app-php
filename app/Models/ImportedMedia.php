<?php

namespace App\Models;

use Database\Factories\ImportedMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Locally generated renditions of one source image.
 *
 * @property int $id
 * @property int $imported_yacht_id
 * @property string $kind
 * @property string $source_url
 * @property string|null $source_sha256
 * @property string|null $caption
 * @property int $sort
 * @property array<string, array{path: string, width: int, height: int}> $renditions
 */
#[Fillable([
    'imported_yacht_id', 'kind', 'source_url', 'source_sha256', 'caption',
    'sort', 'renditions',
])]
class ImportedMedia extends Model
{
    /** @use HasFactory<ImportedMediaFactory> */
    use HasFactory;

    protected $table = 'imported_media';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'renditions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ImportedYacht, $this>
     */
    public function yacht(): BelongsTo
    {
        return $this->belongsTo(ImportedYacht::class, 'imported_yacht_id');
    }

    /**
     * Public URLs for a rendition group, keyed by width — ready for srcset.
     *
     * @return array<int, string>
     */
    public function urlsFor(string $prefix = ''): array
    {
        $disk = Storage::disk(config('openyacht.media.disk'));

        return collect($this->renditions)
            ->filter(fn (array $rendition, string $key): bool => $prefix === ''
                ? str_starts_with($key, 'w')
                : str_starts_with($key, $prefix))
            ->mapWithKeys(fn (array $rendition): array => [
                $rendition['width'] => $disk->url($rendition['path']),
            ])
            ->sortKeys()
            ->all();
    }
}
