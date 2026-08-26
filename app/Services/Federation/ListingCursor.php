<?php

namespace App\Services\Federation;

use Illuminate\Support\Carbon;

/**
 * Opaque cursor pagination for /listings (API-2). Keyset-based on
 * (federation_updated_at, uuid) so concurrent writes never skip or
 * duplicate rows — the reason offset pagination is not used. The uuid is
 * the tie-breaker rather than the row id because the feed unions sale and
 * charter listings from separate tables: row ids collide across tables,
 * canonical UUIDs never do, and their ASCII ordering is collation-stable
 * across SQLite, MySQL, and MariaDB.
 *
 * // api-design.md §Listings
 */
class ListingCursor
{
    /**
     * @param  array{updated_at: string, uuid: string}  $position
     */
    public static function encode(array $position): string
    {
        return base64_encode(json_encode($position, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{updated_at: Carbon, uuid: string}|null
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

        if (! is_array($position) || ! is_string($position['updated_at'] ?? null) || ! is_string($position['uuid'] ?? null)) {
            return null;
        }

        return [
            'updated_at' => Carbon::parse($position['updated_at']),
            'uuid' => $position['uuid'],
        ];
    }
}
