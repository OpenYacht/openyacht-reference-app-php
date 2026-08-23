<?php

namespace App\Services\Federation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches and validates a partner's discovery document.
 *
 * Always HTTPS with default (strict) TLS verification — a well-known
 * document or any federation traffic over plain HTTP or invalid TLS is
 * never accepted (FP-2).
 *
 * // federation-protocol.md §Discovery: the well-known endpoint
 */
class WellKnownClient
{
    /**
     * @return array<string, mixed> the validated discovery document
     *
     * @throws InvalidWellKnownDocument
     */
    public function fetch(string $domain): array
    {
        try {
            $response = Http::timeout(15)
                ->get("https://{$domain}/.well-known/openyacht")
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new InvalidWellKnownDocument(
                "Could not fetch the well-known document for [{$domain}]: {$e->getMessage()}",
                previous: $e,
            );
        }

        $document = $response->json();

        if (! is_array($document)) {
            throw new InvalidWellKnownDocument("The well-known document for [{$domain}] is not valid JSON.");
        }

        $this->validate($domain, $document);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validate(string $domain, array $document): void
    {
        $nodeUuid = data_get($document, 'node.uuid');
        $keys = data_get($document, 'keys');

        $keysAreValid = is_array($keys)
            && $keys !== []
            && collect($keys)->every(
                fn ($key): bool => is_array($key)
                    && is_string($key['key_id'] ?? null)
                    && is_string($key['public_key'] ?? null),
            );

        if (! is_string(data_get($document, 'openyacht'))
            || ! is_string($nodeUuid)
            || ! $keysAreValid) {
            throw new InvalidWellKnownDocument(
                "The well-known document for [{$domain}] is missing required fields (openyacht, node.uuid, keys).",
            );
        }
    }
}
