<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPartnerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageFederation->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:255',
                // A bare hostname: no scheme, no path, no port.
                'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i',
                // A node cannot federate with itself: it would sync its
                // own listings back as partner copies.
                Rule::notIn([strtolower((string) config('openyacht.domain'))]),
                'unique:federation_partners,domain',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => __('federation.domain_invalid'),
            'domain.not_in' => __('federation.domain_is_self'),
            'domain.unique' => __('federation.domain_exists'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => strtolower(trim($this->input('domain')))]);
        }
    }
}
