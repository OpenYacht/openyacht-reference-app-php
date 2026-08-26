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
     * @return array<string, list<array<string, mixed>>>
     */
    protected function remoteMediaProps(array $payload): array
    {
        $linkList = fn (mixed $items): array => collect(is_array($items) ? $items : [])
            ->map(fn ($item): ?array => is_array($item) && $this->httpsUrlOrNull($item['url'] ?? null) !== null
                ? [
                    'url' => $item['url'],
                    'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                ]
                : null)
            ->filter()
            ->values()
            ->all();

        return [
            'layouts' => collect(data_get($payload, 'media.layouts', []))
                ->map(fn ($item): ?array => is_array($item) && $this->httpsUrlOrNull($item['url'] ?? null) !== null
                    ? [
                        'url' => $item['url'],
                        'thumbnail_url' => $this->httpsUrlOrNull($item['thumbnail_url'] ?? null),
                        'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                    ]
                    : null)
                ->filter()
                ->values()
                ->all(),
            'videos' => $linkList(data_get($payload, 'media.videos')),
            'tours' => $linkList(data_get($payload, 'media.tours')),
            'documents' => $linkList(data_get($payload, 'media.documents')),
        ];
    }
}
