<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('updateRole', $this->routeUser());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * Enforce the role-hierarchy invariants that depend on the requested
     * value: only super_admins may assign or remove the super_admin role,
     * and the last super_admin can never be demoted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $target = $this->routeUser();
            $requestedRole = Role::from($this->string('role')->value());

            $touchesSuperAdmin = $requestedRole === Role::SuperAdmin
                || $target->hasRole(Role::SuperAdmin);

            if ($touchesSuperAdmin && ! $this->user()->hasRole(Role::SuperAdmin)) {
                $validator->errors()->add('role', 'Only super admins may assign or remove the super admin role.');

                return;
            }

            $demotesLastSuperAdmin = $target->hasRole(Role::SuperAdmin)
                && $requestedRole !== Role::SuperAdmin
                && User::role(Role::SuperAdmin)->count() <= 1;

            if ($demotesLastSuperAdmin) {
                $validator->errors()->add('role', 'At least one super admin must always exist.');
            }
        });
    }

    private function routeUser(): User
    {
        $user = $this->route('user');

        assert($user instanceof User);

        return $user;
    }
}
