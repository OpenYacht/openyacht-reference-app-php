<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The vendored category vocabulary — shared-vocabulary identifiers for
 * specifications.category. Like builder slugs, a non-null category slug
 * on the wire is a vocabulary claim and is never invented; data entry is
 * a fixed choice with an unlisted escape hatch (name + null slug).
 *
 * // listing-schema.md §Specifications — Classification, §Shared vocabulary
 */
class CategoryVocabulary
{
    /** @var array<string, array{slug: string, name: string}>|null */
    private ?array $bySlug = null;

    public function has(string $slug): bool
    {
        $this->load();

        return isset($this->bySlug[$slug]);
    }

    public function canonicalName(string $slug): ?string
    {
        $this->load();

        return $this->bySlug[$slug]['name'] ?? null;
    }

    /**
     * @return list<array{slug: string, name: string}>
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

        $path = resource_path('registry/categories.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The vendored category vocabulary is missing at [{$path}].");
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ! is_array($document['categories'] ?? null)) {
            throw new RuntimeException('The vendored category vocabulary is malformed.');
        }

        $this->bySlug = collect($document['categories'])
            ->keyBy('slug')
            ->all();
    }
}
