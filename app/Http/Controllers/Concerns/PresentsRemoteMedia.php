<?php

namespace App\Http\Controllers\Concerns;

/**
 * Rendering a partner's media from its verbatim wire payload. Inbound
 * media URLs are untrusted (FP-14): only https URLs are ever handed to
 * a page, whatever the payload claims.
 */
trait PresentsRemoteMedia
{
    protected function httpsUrlOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * The beyond-gallery media lists of a wire payload, https-validated:
     * layout plans (grids prefer the authority thumbnail, LS-16), video
     * and tour links, and any documents the partner's grants included —
     * a withheld documents group arrives as [] already (LS-14).
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     layouts: array<int, array{url: string, thumbnail_url: string|null, caption: string|null}>,
     *     videos: array<int, array{url: string, caption: string|null}>,
     *     tours: array<int, array{url: string, caption: string|null}>,
     *     documents: array<int, array{url: string, caption: string|null}>,
     * }
     */
    protected function remoteMediaProps(array $payload): array
    {
        $items = function (mixed $items): array {
            /** @var array<int, mixed> $list */
            $list = is_array($items) ? $items : [];

            return $list;
        };

        $linkList = fn (mixed $list): array => collect($items($list))
            ->map(function ($item): ?array {
                $url = is_array($item) ? $this->httpsUrlOrNull($item['url'] ?? null) : null;

                return $url === null ? null : [
                    'url' => $url,
                    'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'layouts' => collect($items(data_get($payload, 'media.layouts')))
                ->map(function ($item): ?array {
                    $url = is_array($item) ? $this->httpsUrlOrNull($item['url'] ?? null) : null;

                    return $url === null ? null : [
                        'url' => $url,
                        'thumbnail_url' => $this->httpsUrlOrNull($item['thumbnail_url'] ?? null),
                        'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'videos' => $linkList(data_get($payload, 'media.videos')),
            'tours' => $linkList(data_get($payload, 'media.tours')),
            'documents' => $linkList(data_get($payload, 'media.documents')),
        ];
    }
}
