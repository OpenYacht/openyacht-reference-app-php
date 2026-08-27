<?php

namespace App\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * Enforce the half of the role hierarchy that depends on the requested
     * value: only super_admins may mint another super_admin. This mirrors
     * UpdateUserRoleRequest, which guards the same invariant on role changes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $requestedRole = Role::from($this->string('role')->value());

            if ($requestedRole === Role::SuperAdmin && ! $this->user()->hasRole(Role::SuperAdmin)) {
                $validator->errors()->add('role', __('users.super_admin_only'));
            }
        });
    }
}
