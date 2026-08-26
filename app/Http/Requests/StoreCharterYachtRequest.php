<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesListingFields;
use App\Models\CharterYacht;
use App\Services\Federation\DestinationRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a charter listing's full wire schema at data entry: the
 * shared field set plus the charter side of the type conditional — the
 * rate block (money as digit strings, API-12), operating areas whose
 * slugs must come from the vendored destination registry (an unlisted
 * cruising ground carries a null slug — authorities never invent slugs),
 * base ports, and crew. There is no price: charter listings have no
 * asking price by design.
 *
 * // listing-schema.md §Charter
 */
class StoreCharterYachtRequest extends FormRequest
{
    use ValidatesListingFields;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CharterYacht::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->sharedListingRules(),

            // Rates
            'rates' => ['nullable', 'array'],
            'rates.*.season' => ['required', 'string', 'max:100'],
            'rates.*.rate_type' => ['required', Rule::in(['weekly', 'daily'])],
            'rates.*.amount_min' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
            'rates.*.amount_max' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
            'rates.*.currency' => ['nullable', 'required_with:rates.*.amount_min', 'size:3', 'uppercase'],
            'rates.*.contract_terms' => ['nullable', 'string', 'max:100'],
            'rates.*.apa_percent' => ['nullable', 'numeric', 'between:0,100'],
            'rates.*.vat_percent' => ['nullable', 'numeric', 'between:0,100'],
            'rates.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'rates.*.valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:rates.*.valid_from'],

            // Operating areas
            'operating_areas' => ['nullable', 'array'],
            'operating_areas.*.name' => ['required', 'string', 'max:255'],
            'operating_areas.*.slug' => ['nullable', 'string', 'max:100'],
            'operating_areas.*.season' => ['nullable', 'string', 'max:100'],

            // Base ports (plain strings on the wire)
            'summer_base_port' => ['nullable', 'string', 'max:255'],
            'winter_base_port' => ['nullable', 'string', 'max:255'],

            // Crew — personal data; distribution additionally requires the
            // attestation below (LS-15).
            'crew' => ['nullable', 'array'],
            'crew.*.role' => ['required', 'string', 'max:100'],
            'crew.*.name' => ['nullable', 'string', 'max:255'],
            'crew.*.nationality' => ['nullable', 'string', 'max:100'],
            'crew.*.bio' => ['nullable', 'string', 'max:5000'],
            'crew.*.photo_url' => ['nullable', 'url', 'max:500'],
            'crew.*.tba' => ['boolean'],
            'crew_attested' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->checkRegistrySlugs($validator);

            $registry = app(DestinationRegistry::class);

            foreach ((array) $this->input('operating_areas', []) as $index => $area) {
                $slug = is_array($area) ? ($area['slug'] ?? null) : null;

                if (is_string($slug) && $slug !== '' && ! $registry->has($slug)) {
                    $validator->errors()->add("operating_areas.{$index}.slug", __('yachts.unknown_destination_slug'));
                }
            }
        });
    }
}
