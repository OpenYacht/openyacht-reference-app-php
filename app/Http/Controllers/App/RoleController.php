<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRolePermissionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(PermissionEnum::ManageUsers->value);

        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->get()
            ->keyBy('name');

        return Inertia::render('roles/Index', [
            'roles' => collect(RoleEnum::cases())
                ->map(fn (RoleEnum $roleEnum): array => [
                    'value' => $roleEnum->value,
                    'label' => $roleEnum->label(),
                    'editable' => $roleEnum !== RoleEnum::SuperAdmin,
                    'users_count' => $roles->get($roleEnum->value)->users_count ?? 0,
                    'permissions' => $roles->get($roleEnum->value)?->permissions->pluck('name')->all() ?? [],
                ])
                ->all(),
            'permissions' => collect(PermissionEnum::cases())
                ->map(fn (PermissionEnum $permission): array => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                ])
                ->all(),
            'canEdit' => request()->user()->can(PermissionEnum::ManageRoles->value),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, string $role): RedirectResponse
    {
        $roleEnum = RoleEnum::from($role);
        $roleModel = Role::findByName($roleEnum->value);

        $previous = $roleModel->permissions->pluck('name')->all();
        $requested = $request->array('permissions');

        $roleModel->syncPermissions($requested);

        activity('roles')
            ->causedBy($request->user())
            ->withProperties([
                'role' => $roleEnum->value,
                'from' => $previous,
                'to' => $requested,
            ])
            ->event('permissions_changed')
            ->log("Permissions changed for {$roleEnum->value}");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('roles_page.permissions_changed', ['role' => $roleEnum->label()]),
        ]);

        return back();
    }
}
