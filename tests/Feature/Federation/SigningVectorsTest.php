<?php

use App\Enums\FederationErrorCode;
use App\Enums\KeyStatus;
use App\Models\FederationKey;
use App\Services\Federation\KeyManager;
use App\Services\Federation\Signer;
use App\Services\Federation\Verifier;
use Illuminate\Support\Carbon;

/**
 * The published request-signing test vectors, plus the five negative tests
 * every verifier must pass. Reproducing these byte-for-byte is the
 * interoperability gate for the whole federation layer.
 *
 * // signing-test-vectors.md
 */
const VECTOR_SEED = 'OpenYacht-test-vector-seed-00001';
const VECTOR_PUBLIC_KEY = 'QKcwbi+S0spqvUIba9P45r2SDvKqbXmjCb6zsTn51Ac=';
const VECTOR_KEY_ID = '25f0c5c537a07c58';

const VECTOR_1_PATH = '/openyacht/v1/listings?updated_since=2026-08-01T00:00:00Z&page_size=50';
const VECTOR_1_TIMESTAMP = '2026-08-21T09:00:00Z';
const VECTOR_1_SIGNATURE = '0ZS5EQbB26H01ovHjBIJeYIp2hpK1rmB11zNr89HOKmbWsrTaAbfLXGrJ8kzigOBn8+3Z9ADf0g46/K9HInYAw==';

const VECTOR_2_PATH = '/openyacht/v1/partners/request';
const VECTOR_2_TIMESTAMP = '2026-08-21T09:05:00Z';
const VECTOR_2_BODY = '{"message":"Requesting partnership for co-brokerage.","contact_email":"broker@sender.example"}';
const VECTOR_2_SIGNATURE = 'GdI9tqtzMIm3fzSArP8DHu1P2iKbcyOHQ9rST27sbeXXD7w9vPmeXBXmShjTwAxuJYrtuokrY7VGNvTzdGY8AA==';

function vectorKey(): FederationKey
{
    $keypair = sodium_crypto_sign_seed_keypair(VECTOR_SEED);

    return FederationKey::factory()->make([
        'key_id' => KeyManager::deriveKeyId(sodium_crypto_sign_publickey($keypair)),
        'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'status' => KeyStatus::Active,
    ]);
}

function vectorPublishedKeys(): array
{
    return [VECTOR_KEY_ID => VECTOR_PUBLIC_KEY];
}

test('the test keypair reproduces the published public key and key ID', function () {
    $key = vectorKey();

    expect($key->public_key)->toBe(VECTOR_PUBLIC_KEY)
        ->and($key->key_id)->toBe(VECTOR_KEY_ID);
})->group('FP-3');

test('vector 1: a bodyless GET signs byte-for-byte', function () {
    config(['openyacht.domain' => 'sender.example']);

    $headers = app(Signer::class)->headers(
        method: 'GET',
        pathWithQuery: VECTOR_1_PATH,
        receivingHost: 'receiver.example',
        key: vectorKey(),
        timestamp: Carbon::parse(VECTOR_1_TIMESTAMP),
    );

    expect($headers['X-OpenYacht-Signature'])->toBe(VECTOR_1_SIGNATURE)
        ->and($headers['X-OpenYacht-Node'])->toBe('sender.example')
        ->and($headers['X-OpenYacht-Key'])->toBe(VECTOR_KEY_ID)
        ->and($headers['X-OpenYacht-Timestamp'])->toBe(VECTOR_1_TIMESTAMP);
})->group('FP-3', 'FP-7');

test('vector 2: a POST with JSON body signs byte-for-byte', function () {
    config(['openyacht.domain' => 'sender.example']);

    $headers = app(Signer::class)->headers(
        method: 'POST',
        pathWithQuery: VECTOR_2_PATH,
        receivingHost: 'receiver.example',
        rawBody: VECTOR_2_BODY,
        key: vectorKey(),
        timestamp: Carbon::parse(VECTOR_2_TIMESTAMP),
    );

    expect($headers['X-OpenYacht-Signature'])->toBe(VECTOR_2_SIGNATURE);
})->group('FP-3', 'FP-7');

test('the verifier accepts both vectors', function () {
    $verifier = app(Verifier::class);

    $vector1 = $verifier->verify(
        method: 'GET',
        pathWithQuery: VECTOR_1_PATH,
        receivingHost: 'receiver.example',
        rawBody: '',
        senderKeyId: VECTOR_KEY_ID,
        timestamp: VECTOR_1_TIMESTAMP,
        signature: VECTOR_1_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_1_TIMESTAMP),
    );

    $vector2 = $verifier->verify(
        method: 'POST',
        pathWithQuery: VECTOR_2_PATH,
        receivingHost: 'receiver.example',
        rawBody: VECTOR_2_BODY,
        senderKeyId: VECTOR_KEY_ID,
        timestamp: VECTOR_2_TIMESTAMP,
        signature: VECTOR_2_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_2_TIMESTAMP),
    );

    expect($vector1->verified)->toBeTrue()
        ->and($vector2->verified)->toBeTrue();
})->group('FP-7');

test('negative 1: any changed byte of the signing string invalidates the signature', function () {
    $result = app(Verifier::class)->verify(
        method: 'GET',
        pathWithQuery: '/openyacht/v1/listings?updated_since=2026-08-01T00:00:00Z&page_size=51',
        receivingHost: 'receiver.example',
        rawBody: '',
        senderKeyId: VECTOR_KEY_ID,
        timestamp: VECTOR_1_TIMESTAMP,
        signature: VECTOR_1_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_1_TIMESTAMP),
    );

    expect($result->verified)->toBeFalse()
        ->and($result->error)->toBe(FederationErrorCode::SignatureInvalid);
})->group('FP-7');

test('negative 2: a header timestamp differing from the signed one invalidates the signature', function () {
    $result = app(Verifier::class)->verify(
        method: 'GET',
        pathWithQuery: VECTOR_1_PATH,
        receivingHost: 'receiver.example',
        rawBody: '',
        senderKeyId: VECTOR_KEY_ID,
        timestamp: '2026-08-21T09:01:00Z',
        signature: VECTOR_1_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse('2026-08-21T09:01:00Z'),
    );

    expect($result->verified)->toBeFalse()
        ->and($result->error)->toBe(FederationErrorCode::SignatureInvalid);
})->group('FP-7');

test('negative 3: a validly signed timestamp outside the window is rejected as out of range', function () {
    $result = app(Verifier::class)->verify(
        method: 'GET',
        pathWithQuery: VECTOR_1_PATH,
        receivingHost: 'receiver.example',
        rawBody: '',
        senderKeyId: VECTOR_KEY_ID,
        timestamp: VECTOR_1_TIMESTAMP,
        signature: VECTOR_1_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_1_TIMESTAMP)->addSeconds(301),
    );

    expect($result->verified)->toBeFalse()
        ->and($result->error)->toBe(FederationErrorCode::TimestampOutOfRange);
})->group('FP-8');

test('negative 4: a body altered after signing invalidates the signature', function () {
    $alteredBody = '{"message": "Requesting partnership for co-brokerage.","contact_email":"broker@sender.example"}';

    $result = app(Verifier::class)->verify(
        method: 'POST',
        pathWithQuery: VECTOR_2_PATH,
        receivingHost: 'receiver.example',
        rawBody: $alteredBody,
        senderKeyId: VECTOR_KEY_ID,
        timestamp: VECTOR_2_TIMESTAMP,
        signature: VECTOR_2_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_2_TIMESTAMP),
    );

    expect($result->verified)->toBeFalse()
        ->and($result->error)->toBe(FederationErrorCode::SignatureInvalid);
})->group('FP-7');

test('negative 5: a key ID absent from the published keys is rejected as signature invalid', function () {
    $result = app(Verifier::class)->verify(
        method: 'GET',
        pathWithQuery: VECTOR_1_PATH,
        receivingHost: 'receiver.example',
        rawBody: '',
        senderKeyId: 'fe06271acc7d35b9',
        timestamp: VECTOR_1_TIMESTAMP,
        signature: VECTOR_1_SIGNATURE,
        publishedKeys: vectorPublishedKeys(),
        now: Carbon::parse(VECTOR_1_TIMESTAMP),
    );

    expect($result->verified)->toBeFalse()
        ->and($result->error)->toBe(FederationErrorCode::SignatureInvalid);
})->group('FP-7');
