<?php

use App\Services\Federation\KeyManager;
use App\Services\Federation\SigningString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A fresh Ed25519 keypair for impersonating a partner node in tests.
 *
 * @return array{key_id: string, public_key: string, secret_key: string}
 */
function federationTestKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    return [
        'key_id' => KeyManager::deriveKeyId($publicKey),
        'public_key' => base64_encode($publicKey),
        'secret_key' => sodium_crypto_sign_secretkey($keypair),
    ];
}

/**
 * The four X-OpenYacht-* headers for a request signed as a partner node.
 *
 * @return array<string, string>
 */
function federationSignedHeaders(
    string $senderDomain,
    string $keyId,
    string $secretKey,
    string $method,
    string $pathWithQuery,
    string $receivingHost,
    string $body = '',
    ?string $timestamp = null,
): array {
    $timestamp ??= now()->utc()->format('Y-m-d\TH:i:s\Z');

    $signingString = SigningString::build($method, $pathWithQuery, $receivingHost, $timestamp, $body);

    return [
        'X-OpenYacht-Node' => $senderDomain,
        'X-OpenYacht-Key' => $keyId,
        'X-OpenYacht-Timestamp' => $timestamp,
        'X-OpenYacht-Signature' => base64_encode(sodium_crypto_sign_detached($signingString, $secretKey)),
    ];
}
