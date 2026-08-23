<?php

namespace Database\Factories;

use App\Enums\TrustLevel;
use App\Models\FederationPartner;
use App\Services\Federation\KeyManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationPartner>
 */
class FederationPartnerFactory extends Factory
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
            'domain' => 'openyacht.'.fake()->unique()->domainName(),
            'node_uuid' => (string) Str::uuid7(),
            'keys_json' => [
                [
                    'key_id' => KeyManager::deriveKeyId($publicKey),
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode($publicKey),
                    'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
            'keys_fetched_at' => now(),
            'trust_level' => TrustLevel::Provisional,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => ['trust_level' => TrustLevel::Verified]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['trust_level' => TrustLevel::Blocked]);
    }

    public function unreachableSince(int $days): static
    {
        return $this->state(fn (): array => [
            'last_ok_at' => now()->subDays($days),
            'consecutive_failures' => 5,
        ]);
    }
}
