<?php

namespace App\Services\Federation;

use App\Models\FederationKey;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Signs outbound federation requests.
 *
 * Produces the four X-OpenYacht-* headers every signed request carries
 * (FP-6). The signature is Ed25519 over the signing string, base64-encoded.
 *
 * // federation-protocol.md §Request Signing
 */
class Signer
{
    public function __construct(private KeyManager $keys) {}

    /**
     * Build the signed headers for an outbound federation request.
     *
     * @return array{
     *     X-OpenYacht-Node: string,
     *     X-OpenYacht-Key: string,
     *     X-OpenYacht-Timestamp: string,
     *     X-OpenYacht-Signature: string,
     * }
     */
    public function headers(
        string $method,
        string $pathWithQuery,
        string $receivingHost,
        string $rawBody = '',
        ?FederationKey $key = null,
        ?Carbon $timestamp = null,
    ): array {
        $key ??= $this->keys->activeKey();

        if ($key === null) {
            throw new RuntimeException('No active federation key. Run: php artisan openyacht:install');
        }

        $timestampValue = ($timestamp ?? now())->utc()->format('Y-m-d\TH:i:s\Z');

        $signingString = SigningString::build(
            $method,
            $pathWithQuery,
            $receivingHost,
            $timestampValue,
            $rawBody,
        );

        $signature = sodium_crypto_sign_detached($signingString, $key->rawSecretKey());

        return [
            'X-OpenYacht-Node' => (string) config('openyacht.domain'),
            'X-OpenYacht-Key' => $key->key_id,
            'X-OpenYacht-Timestamp' => $timestampValue,
            'X-OpenYacht-Signature' => base64_encode($signature),
        ];
    }
}
