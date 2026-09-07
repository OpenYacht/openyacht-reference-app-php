<?php

namespace App\Services\Federation;

/**
 * Builds the node's discovery document served at /.well-known/openyacht.
 *
 * The endpoints map only advertises endpoints this node actually serves;
 * `subscriptions` appears because the optional push feature is
 * implemented and advertised in capabilities (api-design.md
 * §Subscriptions).
 *
 * // federation-protocol.md §Discovery: the well-known endpoint
 */
class WellKnownDocument
{
    public function __construct(private KeyManager $keys) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openyacht' => '1.0',
            'protocol_versions' => config('openyacht.protocol_versions'),
            'node' => [
                'uuid' => config('openyacht.node_uuid'),
                'name' => config('openyacht.node_name'),
                'software' => config('openyacht.software'),
                'website' => config('openyacht.website'),
            ],
            'keys' => $this->keys->publishedKeys()
                ->map(fn ($key): array => [
                    'key_id' => $key->key_id,
                    'algorithm' => 'ed25519',
                    'public_key' => $key->public_key,
                    'created_at' => $key->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ])
                ->values()
                ->all(),
            'endpoints' => [
                'listings' => '/openyacht/v1/listings',
                'partners' => '/openyacht/v1/partners',
                'subscriptions' => '/openyacht/v1/subscriptions',
                'health' => '/openyacht/v1/health',
                'capabilities' => '/openyacht/v1/capabilities',
            ],
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
