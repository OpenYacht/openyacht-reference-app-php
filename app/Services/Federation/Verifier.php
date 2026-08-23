<?php

namespace App\Services\Federation;

use App\Enums\FederationErrorCode;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Verifies inbound federation request signatures.
 *
 * This service performs the cryptographic half of the verification
 * procedure: the timestamp window check, key selection, signing-string
 * reconstruction, and the Ed25519 check. The partner-level steps (blocked
 * partners, the one permitted well-known refetch, node-UUID change
 * detection) belong to the middleware/partner layer that calls it.
 *
 * // federation-protocol.md §Request Signing — Verification procedure
 */
class Verifier
{
    /**
     * Verify a request against the sender's published keys.
     *
     * The timestamp window is checked first: a request outside ±300 seconds
     * is rejected as TIMESTAMP_OUT_OF_RANGE without the signature check
     * being the deciding factor (FP-8, signing-test-vectors.md negative
     * test 3).
     *
     * @param  array<string, string>  $publishedKeys  key_id => base64 raw 32-byte public key
     */
    public function verify(
        string $method,
        string $pathWithQuery,
        string $receivingHost,
        string $rawBody,
        string $senderKeyId,
        string $timestamp,
        string $signature,
        array $publishedKeys,
        ?CarbonInterface $now = null,
    ): VerificationResult {
        if (! $this->timestampWithinWindow($timestamp, $now ?? now())) {
            return VerificationResult::failed(FederationErrorCode::TimestampOutOfRange);
        }

        $publicKey = $publishedKeys[$senderKeyId] ?? null;

        if ($publicKey === null) {
            return VerificationResult::failed(FederationErrorCode::SignatureInvalid);
        }

        $rawPublicKey = base64_decode($publicKey, strict: true);
        $rawSignature = base64_decode($signature, strict: true);

        if ($rawPublicKey === false || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return VerificationResult::failed(FederationErrorCode::SignatureInvalid);
        }

        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return VerificationResult::failed(FederationErrorCode::SignatureInvalid);
        }

        $signingString = SigningString::build(
            $method,
            $pathWithQuery,
            $receivingHost,
            $timestamp,
            $rawBody,
        );

        if (! sodium_crypto_sign_verify_detached($rawSignature, $signingString, $rawPublicKey)) {
            return VerificationResult::failed(FederationErrorCode::SignatureInvalid);
        }

        return VerificationResult::passed();
    }

    /**
     * Replay protection: the timestamp must be within ±300 seconds of
     * server time (FP-8).
     */
    private function timestampWithinWindow(string $timestamp, CarbonInterface $now): bool
    {
        try {
            $parsed = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp, 'UTC');
        } catch (InvalidFormatException) {
            return false;
        }

        if ($parsed === null) {
            return false;
        }

        $tolerance = (int) config('openyacht.timestamp_tolerance_seconds', 300);

        return abs($parsed->diffInSeconds($now->utc())) <= $tolerance;
    }
}
