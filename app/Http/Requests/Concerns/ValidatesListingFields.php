<?php

namespace App\Http\Requests\Concerns;

use App\Services\Federation\BuilderRegistry;
use App\Services\Federation\CategoryVocabulary;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The validation rules sale and charter listings share — everything in
 * the wire schema outside the type conditional: money as digit strings
 * (API-12), closed enums exactly as defined (LS-6), and builder/category
 * slugs against the vendored registries (LS-11). The type-specific rules
 * (price on sale, the charter block on charter) live in the requests.
 *
 * // listing-schema.md
 */
trait ValidatesListingFields
{
    /**
     * @return array<string, mixed>
     */
    protected function sharedListingRules(): array
    {
        return [
            // Listing
            'name' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'condition' => ['nullable', 'in:new,used'],

            // Location
            'location_display' => ['nullable', 'string', 'max:255'],
            'location_city' => ['nullable', 'string', 'max:255'],
            'location_state' => ['nullable', 'string', 'max:255'],
            'location_country' => ['nullable', 'size:2', 'uppercase'],
            'location_marina' => ['nullable', 'string', 'max:255'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lon' => ['nullable', 'numeric', 'between:-180,180'],

            // Vessel
            'builder_slug' => ['nullable', 'string'],
            'builder_name' => ['nullable', 'string', 'max:255', 'required_without:builder_slug'],
            'model_name' => ['nullable', 'string', 'max:255'],
            'model_slug' => ['nullable', 'string', 'max:255'],
            'year_built' => ['nullable', 'integer', 'between:1800,2100'],
            'refit_year' => ['nullable', 'integer', 'between:1800,2100', 'gte:year_built'],
            'loa_m' => ['nullable', 'numeric', 'between:1,300'],
            'hin' => ['nullable', 'string', 'max:64'],
            'imo' => ['nullable', 'string', 'max:16'],
            'mmsi' => ['nullable', 'string', 'max:16'],
            'official_number' => ['nullable', 'string', 'max:32'],
            'previous_names' => ['nullable', 'string', 'max:1000'],

            // Specifications — dimensions & weights
            'specifications' => ['nullable', 'array'],
            'specifications.beam_m' => ['nullable', 'numeric'],
            'specifications.draft_max_m' => ['nullable', 'numeric'],
            'specifications.draft_min_m' => ['nullable', 'numeric'],
            'specifications.lwl_m' => ['nullable', 'numeric'],
            'specifications.lod_m' => ['nullable', 'numeric'],
            'specifications.bridge_clearance_m' => ['nullable', 'numeric'],
            'specifications.gross_tonnage' => ['nullable', 'numeric'],
            'specifications.displacement_kg' => ['nullable', 'numeric'],

            // Specifications — capacities
            'specifications.fuel_capacity_l' => ['nullable', 'numeric'],
            'specifications.water_capacity_l' => ['nullable', 'numeric'],
            'specifications.holding_tank_l' => ['nullable', 'numeric'],

            // Specifications — performance
            'specifications.cruise_speed_kn' => ['nullable', 'numeric'],
            'specifications.max_speed_kn' => ['nullable', 'numeric'],
            'specifications.range_nmi' => ['nullable', 'numeric'],
            'specifications.fuel_consumption_lph' => ['nullable', 'numeric'],

            // Specifications — construction (closed enums per LS-6)
            'specifications.hull_material' => ['nullable', 'string', 'max:100'],
            'specifications.superstructure_material' => ['nullable', 'string', 'max:100'],
            'specifications.deck_material' => ['nullable', 'string', 'max:100'],
            'specifications.hull_shape' => ['nullable', Rule::in(['planing', 'semi_displacement', 'displacement', 'hydrofoil', 'catamaran', 'trimaran'])],
            'specifications.hull_color' => ['nullable', 'string', 'max:100'],
            'specifications.naval_architect' => ['nullable', 'string', 'max:255'],
            'specifications.exterior_designer' => ['nullable', 'string', 'max:255'],
            'specifications.interior_designer' => ['nullable', 'string', 'max:255'],
            'specifications.fuel_type' => ['nullable', Rule::in(['diesel', 'petrol', 'electric', 'hybrid'])],
            'specifications.flag' => ['nullable', 'string', 'max:100'],
            'specifications.registry_port' => ['nullable', 'string', 'max:255'],

            // Specifications — classification. power_or_sail is the one
            // non-nullable specification on the wire (listing-schema.md
            // §Classification), so data entry requires it.
            'specifications.power_or_sail' => ['required', Rule::in(['power', 'sail'])],
            'specifications.category' => ['nullable', 'array'],
            'specifications.category.name' => ['nullable', 'string', 'max:100'],
            'specifications.category.slug' => ['nullable', 'string', 'max:100'],

            // Specifications — accommodations
            'specifications.cabins' => ['nullable', 'integer', 'min:0'],
            'specifications.sleeps' => ['nullable', 'integer', 'min:0'],
            'specifications.heads' => ['nullable', 'integer', 'min:0'],
            'specifications.guests_cruising' => ['nullable', 'integer', 'min:0'],
            'specifications.guests_entertaining' => ['nullable', 'integer', 'min:0'],
            'specifications.cabin_config' => ['nullable', 'array'],
            'specifications.cabin_config.*' => ['nullable', 'integer', 'min:0'],
            'specifications.berth_config' => ['nullable', 'array'],
            'specifications.berth_config.*' => ['nullable', 'integer', 'min:0'],
            'specifications.crew_accommodation' => ['nullable', 'array'],
            'specifications.crew_accommodation.cabins' => ['nullable', 'integer', 'min:0'],
            'specifications.crew_accommodation.berths' => ['nullable', 'integer', 'min:0'],
            'specifications.crew_accommodation.layout' => ['nullable', 'string', 'max:255'],

            // Specifications — machinery
            'specifications.engines' => ['nullable', 'array'],
            'specifications.engines.*.make' => ['nullable', 'string', 'max:100'],
            'specifications.engines.*.model' => ['nullable', 'string', 'max:100'],
            'specifications.engines.*.year' => ['nullable', 'integer', 'between:1800,2100'],
            'specifications.engines.*.type' => ['nullable', Rule::in(['inboard', 'outboard', 'saildrive', 'pod', 'jet'])],
            'specifications.engines.*.drive_type' => ['nullable', 'string', 'max:100'],
            'specifications.engines.*.power_hp' => ['nullable', 'numeric'],
            'specifications.engines.*.power_kw' => ['nullable', 'numeric'],
            'specifications.engines.*.fuel_type' => ['nullable', Rule::in(['diesel', 'petrol', 'electric', 'hybrid'])],
            'specifications.engines.*.hours' => ['nullable', 'integer', 'min:0'],
            'specifications.engines.*.hours_recorded_at' => ['nullable', 'date_format:Y-m-d'],
            'specifications.engines.*.location' => ['nullable', 'string', 'max:50'],
            'specifications.generators' => ['nullable', 'array'],
            'specifications.generators.*.make' => ['nullable', 'string', 'max:100'],
            'specifications.generators.*.model' => ['nullable', 'string', 'max:100'],
            'specifications.generators.*.power_kw' => ['nullable', 'numeric'],
            'specifications.generators.*.hours' => ['nullable', 'integer', 'min:0'],
            'specifications.generators.*.hours_recorded_at' => ['nullable', 'date_format:Y-m-d'],
            'specifications.tenders' => ['nullable', 'string', 'max:2000'],

            // Descriptions & features
            'descriptions' => ['nullable', 'array'],
            'descriptions.*.section' => ['nullable', 'string', 'max:100'],
            'descriptions.*.content' => ['required_with:descriptions.*', 'string'],
            'features' => ['nullable', 'array'],
            'features.*.category' => ['nullable', 'string', 'max:100'],
            'features.*.name' => ['required_with:features.*', 'string', 'max:255'],
            'features.*.slug' => ['nullable', 'string', 'max:100'],
            'features.*.quantity' => ['nullable', 'integer', 'min:1'],

            // Videos & virtual tours: external-platform links (YouTube,
            // Vimeo, Matterport…), https like all wire media URLs.
            'videos' => ['nullable', 'array'],
            'videos.*.url' => ['required_with:videos.*', 'url:https', 'max:2048'],
            'videos.*.caption' => ['nullable', 'string', 'max:255'],
            'tours' => ['nullable', 'array'],
            'tours.*.url' => ['required_with:tours.*', 'url:https', 'max:2048'],
            'tours.*.caption' => ['nullable', 'string', 'max:255'],

            // Compliance
            'compliance' => ['nullable', 'array'],
            'compliance.not_for_sale_to_us_residents_in_us_waters' => ['nullable', 'boolean'],
            'compliance.vat_status' => ['nullable', 'string', 'max:100'],
            'compliance.ce_certified' => ['nullable', 'boolean'],
            'compliance.mca_compliant' => ['nullable', 'boolean'],
            'compliance.classification' => ['nullable', 'array'],
            'compliance.classification.*.society' => ['nullable', 'string', 'max:100'],
            'compliance.classification.*.notation' => ['nullable', 'string', 'max:255'],
            'compliance.classification.*.next_survey_due' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * A non-null slug on the wire is a claim of registry membership
     * (LS-11) — reject anything the vendored registries do not list.
     */
    protected function checkRegistrySlugs(Validator $validator): void
    {
        $slug = $this->input('builder_slug');

        if (is_string($slug) && $slug !== '' && ! app(BuilderRegistry::class)->has($slug)) {
            $validator->errors()->add('builder_slug', __('yachts.unknown_builder_slug'));
        }

        $categorySlug = $this->input('specifications.category.slug');

        if (is_string($categorySlug) && $categorySlug !== '' && ! app(CategoryVocabulary::class)->has($categorySlug)) {
            $validator->errors()->add('specifications.category.slug', __('yachts.unknown_category_slug'));
        }
    }
}
