<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesListingFields;
use App\Models\SaleYacht;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a sale listing's full wire schema at data entry: the shared
 * field set plus the sale side of the type conditional — the asking
 * price. Money is digit strings (API-12).
 *
 * // listing-schema.md
 */
class StoreYachtRequest extends FormRequest
{
    use ValidatesListingFields;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', SaleYacht::class);
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

            // Price
            'price_amount' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
            'price_currency' => ['nullable', 'required_with:price_amount', 'size:3', 'uppercase'],
            'price_on_application' => ['boolean'],
            'starting_price' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->checkRegistrySlugs($validator);
        });
    }
}
