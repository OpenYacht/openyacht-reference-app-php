<?php

namespace App\Services\Federation;

use App\Enums\KeyStatus;
use App\Models\FederationKey;
use Illuminate\Database\Eloquent\Collection;

/**
 * Generates and manages the node's Ed25519 federation keys.
 *
 * // federation-protocol.md §Keys, §Key Rotation
 */
class KeyManager
{
    /**
     * Generate a fresh Ed25519 keypair and store it with the given status.
     */
    public function generate(KeyStatus $status = KeyStatus::Active): FederationKey
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        return FederationKey::create([
            'key_id' => self::deriveKeyId($publicKey),
            'private_key' => base64_encode($secretKey),
            'public_key' => base64_encode($publicKey),
            'status' => $status,
        ]);
    }

    /**
     * The key currently used to sign outbound requests.
     */
    public function activeKey(): ?FederationKey
    {
        return FederationKey::query()
            ->where('status', KeyStatus::Active)
            ->latest('id')
            ->first();
    }

    /**
     * Keys published in the well-known document: active and retiring, so
     * routine rotation can overlap. Revoked keys are never published.
     *
     * @return Collection<int, FederationKey>
     */
    public function publishedKeys(): Collection
    {
        return FederationKey::query()
            ->whereIn('status', [KeyStatus::Active, KeyStatus::Retiring])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Routine rotation: generate a new active key and keep the old one
     * published as retiring for the overlap window (RECOMMENDED: 48 hours).
     */
    public function rotate(): FederationKey
    {
        $this->activeKey()?->update(['status' => KeyStatus::Retiring]);

        return $this->generate();
    }

    /**
     * Revoke retiring keys once the rotation overlap window has passed,
     * removing them from the well-known document.
     */
    public function retireOverlappedKeys(): int
    {
        return FederationKey::query()
            ->where('status', KeyStatus::Retiring)
            ->update(['status' => KeyStatus::Revoked, 'retired_at' => now()]);
    }

    /**
     * Emergency rotation: revoke every existing key immediately, without an
     * overlap window, and start signing with a fresh keypair. Partners'
     * next verification fails, triggers a well-known refetch, and recovers.
     */
    public function rotateEmergency(): FederationKey
    {
        FederationKey::query()
            ->where('status', '!=', KeyStatus::Revoked)
            ->update(['status' => KeyStatus::Revoked, 'retired_at' => now()]);

        return $this->generate();
    }

    /**
     * Key ID derivation: the first 16 hex characters of the SHA-256 hash of
     * the raw 32-byte public key (FP-3).
     */
    public static function deriveKeyId(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }
}
