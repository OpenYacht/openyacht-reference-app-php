<?php

namespace App\Services\Federation;

use App\Enums\FieldGroup;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Serialises a sale listing to its wire payload for one partner.
 *
 * Every response contains the complete schema: a value the node does not
 * have — or that the partner's sharing rules withhold — is null or []
 * (LS-1). Filtering happens here, server-side, per the gating map
 * (LS-14, API-5): withheld values are nulled, never sent with
 * "please ignore" semantics. Money is strings with ISO 4217 codes
 * (API-12); drafts are never serialised (LS-7 — callers must not pass
 * them).
 *
 * // listing-schema.md
 */
class ListingSerializer
{
    /** Flat specification keys, emitted null when unknown (LS-1, LS-2). */
    private const SPECIFICATION_KEYS = [
        'beam_m', 'draft_max_m', 'draft_min_m', 'lwl_m', 'lod_m',
        'bridge_clearance_m', 'gross_tonnage', 'displacement_kg',
        'fuel_capacity_l', 'water_capacity_l', 'holding_tank_l',
        'cruise_speed_kn', 'max_speed_kn', 'range_nmi', 'fuel_consumption_lph',
        'hull_material', 'superstructure_material', 'deck_material',
        'hull_shape', 'hull_color', 'naval_architect', 'exterior_designer',
        'interior_designer', 'fuel_type', 'flag', 'registry_port',
        'power_or_sail', 'category',
        'cabins', 'sleeps', 'heads', 'guests_cruising', 'guests_entertaining',
        'cabin_config', 'berth_config', 'crew_accommodation',
        'engines', 'generators', 'tenders',
    ];

    /**
     * Serialise for one partner — or, with a null partner, ungated (the
     * internal API's full view; never used on the federation surface).
     *
     * @return array<string, mixed>
     */
    public function serialize(SaleYacht $yacht, ?FederationPartner $partner): array
    {
        $granted = fn (FieldGroup $group): bool => $partner === null || $partner->hasFieldGroup($group);

        return [
            'id' => $yacht->canonicalUri(),
            'type' => 'sale',
            'status' => $yacht->status->value,
            'updated_at' => $yacht->federation_updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'listed_at' => $yacht->listed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'condition' => $yacht->condition,
            'agreement' => ['type' => null, 'co_brokerage' => null],
            'vessel' => $this->vessel($yacht, $granted(FieldGroup::VesselIdentifiers)),
            'listing' => $this->listing($yacht, $granted(FieldGroup::Pricing), $granted(FieldGroup::LocationExact), $granted(FieldGroup::History)),
            'specifications' => $this->specifications($yacht),
            'descriptions' => array_values($yacht->descriptions ?? []),
            'features' => array_values($yacht->features ?? []),
            'media' => $this->media($yacht, $granted(FieldGroup::Documents)),
            'charter' => null,
            'usage' => $this->usage(),
            'compliance' => $this->compliance($yacht),
        ];
    }

    /**
     * The tombstone form: served in updated_since results for every
     * listing that became invisible to the requesting partner (API-3).
     *
     * @return array<string, mixed>
     */
    public function tombstone(SaleYacht $yacht): array
    {
        return [
            'id' => $yacht->canonicalUri(),
            'tombstone' => true,
            'status' => $yacht->status->value,
            'updated_at' => $yacht->federation_updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vessel(SaleYacht $yacht, bool $identifiersGranted): array
    {
        $vessel = $yacht->vessel;

        return [
            'hin' => $identifiersGranted ? $vessel->hin : null,
            'imo' => $identifiersGranted ? $vessel->imo : null,
            'mmsi' => $identifiersGranted ? $vessel->mmsi : null,
            'official_number' => $identifiersGranted ? $vessel->official_number : null,
            'builder' => ['name' => $vessel->builder_name, 'slug' => $vessel->builder_slug],
            'model' => ['name' => $vessel->model_name, 'slug' => $vessel->model_slug],
            'year_built' => $vessel->year_built,
            'refit_year' => $vessel->refit_year,
            'loa_m' => $vessel->loa_m,
            'previous_names' => $vessel->previous_names ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(SaleYacht $yacht, bool $pricingGranted, bool $locationGranted, bool $historyGranted): array
    {
        $priceShared = $pricingGranted && ! $yacht->price_on_application;

        return [
            'name' => $yacht->name,
            'summary' => $yacht->summary,
            'price' => [
                'amount' => $priceShared ? $yacht->price_amount : null,
                'currency' => $priceShared ? $yacht->price_currency : null,
                'on_application' => $yacht->price_on_application,
                'starting_price' => $yacht->starting_price,
            ],
            'price_history' => $historyGranted && $pricingGranted
                ? $yacht->priceHistory
                    ->map(fn ($entry): array => [
                        'amount' => $entry->amount,
                        'currency' => $entry->currency,
                        'changed_at' => $entry->changed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    ])
                    ->values()
                    ->all()
                : [],
            'location' => [
                'display' => $yacht->location_display,
                'city' => $yacht->location_city,
                'state' => $yacht->location_state,
                'country' => $yacht->location_country,
                'marina' => $locationGranted ? $yacht->location_marina : null,
                'coordinates' => $locationGranted && $yacht->location_lat !== null && $yacht->location_lon !== null
                    ? ['lat' => $yacht->location_lat, 'lon' => $yacht->location_lon]
                    : null,
            ],
            'brokers' => $yacht->assignedBroker === null ? [] : [[
                'name' => $yacht->assignedBroker->name,
                'title' => null,
                'email' => $yacht->assignedBroker->email,
                'phone' => null,
                'photo_url' => null,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specifications(SaleYacht $yacht): array
    {
        $stored = $yacht->specifications ?? [];
        $complete = [];

        foreach (self::SPECIFICATION_KEYS as $key) {
            $complete[$key] = $stored[$key] ?? match ($key) {
                'cabin_config' => ['double' => null, 'twin' => null, 'triple' => null, 'single' => null, 'convertible' => null],
                'berth_config' => ['king' => null, 'queen' => null, 'double' => null, 'twin' => null, 'single' => null, 'pullman' => null, 'bunk' => null],
                'crew_accommodation' => ['cabins' => null, 'berths' => null, 'layout' => null],
                'engines', 'generators' => [],
                default => null,
            };
        }

        return $complete;
    }

    /**
     * Media with content hashes; the profile hero and its thumbnail are
     * mandatory whenever imagery exists, and a listing with no imagery has
     * profile: null — never a placeholder (LS-8).
     *
     * @return array<string, mixed>
     */
    private function media(SaleYacht $yacht, bool $documentsGranted): array
    {
        $profile = $yacht->getFirstMedia('profile');

        return [
            'profile' => $profile === null ? null : [
                'url' => $profile->getFullUrl(),
                'sha256' => $profile->getCustomProperty('sha256'),
                'width' => $profile->getCustomProperty('width'),
                'height' => $profile->getCustomProperty('height'),
                'caption' => $profile->getCustomProperty('caption'),
                'thumbnail_url' => $profile->getFullUrl('thumbnail'),
            ],
            'gallery' => $yacht->getMedia('gallery')
                ->values()
                ->map(fn (Media $media, int $index): array => [
                    'url' => $media->getFullUrl(),
                    'sha256' => $media->getCustomProperty('sha256'),
                    'category' => $media->getCustomProperty('category'),
                    'width' => $media->getCustomProperty('width'),
                    'height' => $media->getCustomProperty('height'),
                    'caption' => $media->getCustomProperty('caption'),
                    'sort' => $index + 1,
                ])
                ->all(),
            'layouts' => [],
            'videos' => [],
            'tours' => [],
            'documents' => $documentsGranted ? [] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        return [
            'display' => (bool) config('openyacht.usage.display'),
            'attribution_required' => (bool) config('openyacht.usage.attribution_required'),
            'attribution_text' => config('openyacht.usage.attribution_text')
                ?? __('federation.attribution_default', ['name' => config('openyacht.node_name')]),
            'marketing_materials' => (bool) config('openyacht.usage.marketing_materials'),
            'ai_indexing' => (bool) config('openyacht.usage.ai_indexing'),
            'expires_with_listing' => (bool) config('openyacht.usage.expires_with_listing'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compliance(SaleYacht $yacht): array
    {
        $stored = $yacht->compliance ?? [];

        return [
            'not_for_sale_to_us_residents_in_us_waters' => $stored['not_for_sale_to_us_residents_in_us_waters'] ?? null,
            'vat_status' => $stored['vat_status'] ?? null,
            'ce_certified' => $stored['ce_certified'] ?? null,
            'mca_compliant' => $stored['mca_compliant'] ?? null,
            'classification' => $stored['classification'] ?? [],
        ];
    }
}
