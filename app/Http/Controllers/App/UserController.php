<?php

namespace App\Http\Controllers\App;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
     * Create an account, grant it a role, and email the person a link to
     * set their own password. Self-registration is disabled, so this mail
     * is their only way in; the administrator never chooses or handles a
     * credential on their behalf.
     *
     * The account is created with an unusable random password rather than
     * a null one: the column is not nullable, and a random value that
     * nobody holds cannot be guessed into a login before the invitation
     * is accepted. The address is marked verified because an administrator
     * with users.manage vouched for it, matching
     * `php artisan openyacht:create-user`.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $role = Role::from($request->string('role')->value());

        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'password' => Str::password(64),
        ]);

        $user->markEmailAsVerified();
        $user->assignRole($role);

        $user->notify(new UserInvitation(
            Password::broker()->createToken($user),
            $request->user()->name,
        ));

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['role' => $role->value])
            ->event('created')
            ->log("User {$user->email} invited with the {$role->value} role");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('users.invited', [
                'name' => $user->name,
                'email' => $user->email,
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
