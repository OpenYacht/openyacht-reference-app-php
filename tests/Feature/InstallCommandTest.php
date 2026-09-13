<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A fresh installation has to end up usable. Nothing else in the documented
 * local path seeds the role hierarchy, so if openyacht:install does not, the
 * first openyacht:create-user dies on an unknown role and the node cannot be
 * logged into at all.
 */
it('seeds the role hierarchy so the first user can be created', function (): void {
    Role::query()->delete();
    Permission::query()->delete();

    $this->artisan('openyacht:install')->assertSuccessful();

    expect(Role::query()->count())->toBe(count(RoleEnum::cases()))
        ->and(Permission::query()->count())->toBe(count(PermissionEnum::cases()));

    $user = User::factory()->create();
    $user->assignRole(RoleEnum::SuperAdmin);

    expect($user->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();
});

it('is idempotent and leaves a tuned matrix alone', function (): void {
    $this->artisan('openyacht:install')->assertSuccessful();

    $editor = Role::findByName(RoleEnum::Editor->value);
    $editor->syncPermissions([PermissionEnum::ManageMedia->value]);

    $this->artisan('openyacht:install')->assertSuccessful();

    expect($editor->fresh()->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::ManageMedia->value])
        ->and(Role::findByName(RoleEnum::SuperAdmin->value)->permissions)
        ->toHaveCount(count(PermissionEnum::cases()));
});

/**
 * DatabaseSeeder mutes model events, which is what flushes the permission
 * registrar's cache when a permission is created. Without an explicit flush
 * the assignments resolve a pre-creation cache and `php artisan db:seed`
 * dies with "There is no permission named `users.manage`".
 */
it('seeds roles even when model events are muted', function (): void {
    Role::query()->delete();
    Permission::query()->delete();

    Model::withoutEvents(function (): void {
        (new RoleSeeder)->run();
    });

    expect(Role::findByName(RoleEnum::SuperAdmin->value)->permissions)
        ->toHaveCount(count(PermissionEnum::cases()));
});

it('seeds a usable super admin through the default database seeder', function (): void {
    Role::query()->delete();
    Permission::query()->delete();
    User::query()->delete();

    $this->artisan('db:seed')->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->first()->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();
});
