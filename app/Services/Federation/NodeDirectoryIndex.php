<?php

namespace App\Services\Federation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The consumer side of the node directory: the vendored copy of the
 * project's advisory node phonebook, refreshable out of band from the
 * canonical URL only. The URL is deliberately not configurable — a
 * paste-any-URL option would let an operator be talked into loading a
 * bad actor's list, and the directory's whole safety story is that
 * everything verifiable comes from each node's own domain anyway (FP-16).
 *
 * // federation-protocol.md §Finding partners: the node directory
 */
class NodeDirectoryIndex
{
    public const CANONICAL_URL = 'https://openyacht.org/registry/nodes.json';

    private const CACHE_PATH = 'openyacht/node-directory.json';

    public function __construct(private readonly ?string $vendoredPath = null) {}

    /**
     * The refreshed copy when one exists, the vendored copy otherwise.
     *
     * @return list<array{domain: string, name: string, website: string, country: string, listed_at: string}>
     */
    public function entries(): array
    {
        $cached = $this->cache();

        if ($cached !== null) {
            return $cached['nodes'];
        }

        $path = $this->vendoredPath ?? resource_path('registry/nodes.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        $document = $contents !== false ? json_decode($contents, true) : null;

        return is_array($document) ? $this->validNodes($document) : [];
    }

    public function fetchedAt(): ?string
    {
        return $this->cache()['fetched_at'] ?? null;
    }

    /**
     * Refresh from the canonical URL. Throws on any transport, shape, or
     * validation failure — a bad fetch never replaces a good cache.
     *
     * @return int entries now cached
     */
    public function refresh(): int
    {
        try {
            $response = Http::timeout(15)->get(self::CANONICAL_URL);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Could not reach the node directory: {$exception->getMessage()}");
        }

        if (! $response->ok()) {
            throw new RuntimeException("The node directory returned HTTP {$response->status()}.");
        }

        $document = $response->json();

        if (! is_array($document) || ($document['registry'] ?? null) !== 'openyacht-nodes' || ! is_array($document['nodes'] ?? null)) {
            throw new RuntimeException('The node directory response is not a valid openyacht-nodes registry document.');
        }

        $nodes = $this->validNodes($document);

        Storage::disk('local')->put(self::CACHE_PATH, (string) json_encode([
            'nodes' => $nodes,
            'version' => is_string($document['version'] ?? null) ? $document['version'] : null,
            'fetched_at' => now('UTC')->toIso8601ZuluString(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return count($nodes);
    }

    /**
     * @return array{nodes: list<array{domain: string, name: string, website: string, country: string, listed_at: string}>, fetched_at: ?string}|null
     */
    private function cache(): ?array
    {
        $raw = Storage::disk('local')->exists(self::CACHE_PATH)
            ? Storage::disk('local')->get(self::CACHE_PATH)
            : null;
        $cached = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($cached) || ! is_array($cached['nodes'] ?? null)) {
            return null;
        }

        return [
            'nodes' => array_values($cached['nodes']),
            'fetched_at' => is_string($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : null,
        ];
    }

    /**
     * Structural validation mirroring the registry's own lint: entries
     * that do not hold their shape are dropped, never displayed.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{domain: string, name: string, website: string, country: string, listed_at: string}>
     */
    private function validNodes(array $document): array
    {
        $nodes = [];

        foreach (is_array($document['nodes'] ?? null) ? $document['nodes'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $domain = is_string($node['domain'] ?? null) ? strtolower(trim($node['domain'])) : '';

            if (
                preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1
                || ! is_string($node['name'] ?? null) || trim($node['name']) === ''
                || ! is_string($node['website'] ?? null) || ! str_starts_with($node['website'], 'https://')
                || ! is_string($node['country'] ?? null) || preg_match('/^[A-Z]{2}$/', $node['country']) !== 1
                || ! is_string($node['listed_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $node['listed_at']) !== 1
            ) {
                continue;
            }

            $nodes[] = [
                'domain' => $domain,
                'name' => trim($node['name']),
                'website' => $node['website'],
                'country' => $node['country'],
                'listed_at' => $node['listed_at'],
            ];
        }

        return $nodes;
    }
}
