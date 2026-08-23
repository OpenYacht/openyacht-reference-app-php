<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ListingCopy>
 */
class ListingCopyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = 'openyacht.'.fake()->domainName();
        $uuid = (string) Str::uuid7();
        $name = strtoupper(fake()->firstName());

        return [
            'federation_partner_id' => FederationPartner::factory()->state(['domain' => $domain]),
            'canonical_uri' => "https://{$domain}/openyacht/v1/listings/{$uuid}",
            'authority_domain' => $domain,
            'type' => 'sale',
            'status' => ListingStatus::Active,
            'name' => $name,
            'payload' => [
                'id' => "https://{$domain}/openyacht/v1/listings/{$uuid}",
                'type' => 'sale',
                'status' => ListingStatus::Active->value,
                'listing' => ['name' => $name],
                'usage' => [
                    'display' => true,
                    'attribution_required' => true,
                    'attribution_text' => 'Listing courtesy of a partner brokerage',
                    'marketing_materials' => true,
                    'ai_indexing' => true,
                    'expires_with_listing' => true,
                ],
            ],
            'listing_updated_at' => now()->subDay(),
            'received_at' => now(),
            'signature_verified' => true,
        ];
    }

    public function tombstoned(ListingStatus $status = ListingStatus::Withdrawn): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'tombstoned_at' => now(),
        ]);
    }
}
