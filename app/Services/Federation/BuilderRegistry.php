<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The vendored builder registry — the fixed manufacturer vocabulary both
 * sides validate slugs against. Vendored with the application and never
 * fetched at request time (LS-13); a non-null slug on the wire is a claim
 * of registry membership (LS-11); consumers validate incoming slugs and
 * fall back to the name on an unknown one (LS-12).
 *
 * // listing-schema.md §Shared vocabulary — The builder registry
 */
class BuilderRegistry
{
    /** @var array<string, array{slug: string, name: string, country: string|null}>|null */
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
     * @return array{slug: string, name: string, country: string|null}|null
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
     * All builders, for data-entry choice lists (a fixed registry choice
     * with an explicit "unlisted" escape hatch — not a free-text field).
     *
     * @return list<array{slug: string, name: string, country: string|null}>
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

        $path = resource_path('registry/builders.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The vendored builder registry is missing at [{$path}].");
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ! is_array($document['builders'] ?? null)) {
            throw new RuntimeException('The vendored builder registry is malformed.');
        }

        $this->version = is_string($document['version'] ?? null) ? $document['version'] : 'unknown';
        $this->bySlug = collect($document['builders'])
            ->keyBy('slug')
            ->all();
    }
}
