<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The vendored destination registry — the fixed charter-destination
 * vocabulary both sides validate operating_areas[] slugs against.
 * Vendored with the application and never fetched at request time
 * (LS-13); authorities MUST NOT invent destination slugs — an unlisted
 * cruising ground is sent as { name, slug: null } (LS-11 spirit);
 * consumers fall back to the name on an unknown slug (LS-12).
 *
 * // listing-schema.md §Shared vocabulary — The destination registry
 */
class DestinationRegistry
{
    /** @var array<string, array{slug: string, name: string, parent: string|null}>|null */
    private ?array $bySlug = null;

    private ?string $version = null;

    public function version(): string
    {
        $this->load();

        return $this->version;
    }

    public function has(string $slug): bool
    {
        $this->load();

        return isset($this->bySlug[$slug]);
    }

    /**
     * @return array{slug: string, name: string, parent: string|null}|null
     */
    public function get(string $slug): ?array
    {
        $this->load();

        return $this->bySlug[$slug] ?? null;
    }

    /**
     * The registry's canonical display name for a slug, or null.
     */
    public function canonicalName(string $slug): ?string
    {
        return $this->get($slug)['name'] ?? null;
    }

    /**
     * All destinations, for data-entry choice lists (a fixed registry
     * choice with an explicit "unlisted" escape hatch — not a free-text
     * field).
     *
     * @return list<array{slug: string, name: string, parent: string|null}>
     */
    public function all(): array
    {
        $this->load();

        return array_values($this->bySlug);
    }

    private function load(): void
    {
        if ($this->bySlug !== null) {
            return;
        }

        $path = resource_path('registry/destinations.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The vendored destination registry is missing at [{$path}].");
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ! is_array($document['destinations'] ?? null)) {
            throw new RuntimeException('The vendored destination registry is malformed.');
        }

        $this->version = is_string($document['version'] ?? null) ? $document['version'] : 'unknown';
        $this->bySlug = collect($document['destinations'])
            ->keyBy('slug')
            ->all();
    }
}
