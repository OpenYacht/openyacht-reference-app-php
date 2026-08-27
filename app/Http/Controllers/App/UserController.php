<?php

namespace App\Http\Controllers\App;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('users/Index', [
            'users' => User::query()
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(function (User $user): array {
                    $role = $user->getRoleNames()->first();

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $role,
                        'role_label' => $role === null ? null : Role::from($role)->label(),
                        'created_at' => $user->created_at?->toDateString(),
                    ];
                }),
            'roles' => collect(Role::cases())
                ->map(fn (Role $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->all(),
        ]);
    }

    /**
     * Create an account and grant it a role in one step. The email is marked
     * verified because an administrator vouched for it here — there is no
     * invitation round-trip to confirm it, exactly as with
     * `php artisan openyacht:create-user`.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $role = Role::from($request->string('role')->value());

        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'password' => $request->string('password')->value(),
        ]);

        $user->markEmailAsVerified();
        $user->assignRole($role);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['role' => $role->value])
            ->event('created')
            ->log("User {$user->email} created with the {$role->value} role");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('users.created', [
                'name' => $user->name,
                'role' => $role->label(),
            ]),
        ]);

        return back();
    }

    public function update(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $previousRole = $user->getRoleNames()->first();
        $newRole = Role::from($request->string('role')->value());

        $user->syncRoles([$newRole]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['from' => $previousRole, 'to' => $newRole->value])
            ->event('role_changed')
            ->log("Role changed from {$previousRole} to {$newRole->value}");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('users.role_changed', [
                'name' => $user->name,
                'role' => $newRole->label(),
            ]),
        ]);

        return back();
    }
}
