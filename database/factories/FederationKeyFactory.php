<?php

namespace Database\Factories;

use App\Enums\KeyStatus;
use App\Models\FederationKey;
use App\Services\Federation\KeyManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FederationKey>
 */
class FederationKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'key_id' => KeyManager::deriveKeyId($publicKey),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'public_key' => base64_encode($publicKey),
            'status' => KeyStatus::Active,
        ];
    }

    public function retiring(): static
    {
        return $this->state(fn (): array => ['status' => KeyStatus::Retiring]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => KeyStatus::Revoked,
            'retired_at' => now(),
        ]);
    }
}
