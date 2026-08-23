<?php

namespace App\Services\Federation;

use Illuminate\Support\Carbon;

/**
 * Opaque cursor pagination for /listings (API-2). Keyset-based on
 * (federation_updated_at, id) so concurrent writes never skip or
 * duplicate rows — the reason offset pagination is not used.
 *
 * // api-design.md §Listings
 */
class ListingCursor
{
    /**
     * @param  array{updated_at: string, id: int}  $position
     */
    public static function encode(array $position): string
    {
        return base64_encode(json_encode($position, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{updated_at: Carbon, id: int}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, strict: true);

        if ($decoded === false) {
            return null;
        }

        $position = json_decode($decoded, true);

        if (! is_array($position) || ! is_string($position['updated_at'] ?? null) || ! is_int($position['id'] ?? null)) {
            return null;
        }

        return [
            'updated_at' => Carbon::parse($position['updated_at']),
            'id' => $position['id'],
        ];
    }
}
