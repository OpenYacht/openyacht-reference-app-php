<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The vendored well-known features list — shared-vocabulary identifiers
 * for features[].slug. It is non-normative and never gates what a feature
 * can be: the name is free text and always display truth, and a feature
 * with no matching entry is sent with slug null. What it does gate is the
 * slug itself — a non-null slug on the wire is a vocabulary claim and is
 * never invented. The category on each entry is the registry's own
 * grouping label, not an enum; the category a node sends is its own.
 *
 * // listing-schema.md §Features, §Shared vocabulary
 */
class FeatureRegistry
{
    /** @var array<string, array{slug: string, name: string, category: string}>|null */
    private ?array $bySlug = null;

    public function has(string $slug): bool
    {
        $this->load();

        return isset($this->bySlug[$slug]);
    }

    /**
     * @return list<array{slug: string, name: string, category: string}>
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

        $path = resource_path('registry/features.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The vendored features registry is missing at [{$path}].");
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ! is_array($document['features'] ?? null)) {
            throw new RuntimeException('The vendored features registry is malformed.');
        }

        $this->bySlug = collect($document['features'])
            ->keyBy('slug')
            ->all();
    }
}
