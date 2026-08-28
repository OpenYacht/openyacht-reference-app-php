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
    public function __construct(private OutboundUrlGuard $guard) {}

    /**
     * @return array<string, mixed> the validated discovery document
     *
     * @throws InvalidWellKnownDocument
     */
    public function fetch(string $domain): array
    {
        try {
            // The domain can arrive from an unauthenticated header on
            // first contact, so validate it before any outbound request
            // (SSRF), and never follow a redirect that could bounce the
            // trust-anchor fetch to a private host or plain HTTP (FP-2).
            $this->guard->assertPublicHost($domain);

            $response = Http::timeout(15)
                ->withoutRedirecting()
                ->get("https://{$domain}/.well-known/openyacht")
                ->throw();
        } catch (BlockedOutboundHost $e) {
            throw new InvalidWellKnownDocument(
                "Refusing to fetch the well-known document for [{$domain}]: {$e->getMessage()}",
                previous: $e,
            );
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
            && collect($keys)->every(fn ($key): bool => $this->keyIsValid($key));

        if (! is_string(data_get($document, 'openyacht'))
            || ! is_string($nodeUuid)
            || ! $keysAreValid) {
            throw new InvalidWellKnownDocument(
                "The well-known document for [{$domain}] is missing or has malformed required fields (openyacht, node.uuid, keys).",
            );
        }
    }

    /**
     * A key entry is trustworthy only if its material is a real Ed25519
     * public key AND its key_id is the FP-3 digest of that material
     * (substr(sha256(raw_public_key), 0, 16)). Binding the id to the key
     * is what makes key pinning meaningful: without it a pin is a pin on
     * an attacker-chosen label, and a hostile document could relabel its
     * own key with a pinned id to slip past the verifier (FP-3, FP-12).
     *
     * @param  mixed  $key
     */
    private function keyIsValid($key): bool
    {
        if (! is_array($key)
            || ! is_string($key['key_id'] ?? null)
            || ! is_string($key['public_key'] ?? null)
            || ($key['algorithm'] ?? 'ed25519') !== 'ed25519') {
            return false;
        }

        $rawPublicKey = base64_decode($key['public_key'], strict: true);

        if ($rawPublicKey === false
            || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return hash_equals(KeyManager::deriveKeyId($rawPublicKey), $key['key_id']);
    }
}
