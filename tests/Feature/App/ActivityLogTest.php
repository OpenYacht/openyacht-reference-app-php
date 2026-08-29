<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogPruner;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function settingsAdmin(): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));
}

function logEntryAgedDays(string $logName, string $event, int $ageDays): Activity
{
    $activity = activity($logName)->event($event)->log("test {$event}");
    $activity->forceFill(['created_at' => now()->subDays($ageDays)])->save();

    return $activity;
}

test('the activity log page renders for a settings manager', function () {
    logEntryAgedDays('federation', 'partner_approved', 1);

    $this->actingAs(settingsAdmin())
        ->get(route('activity-log.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity-log/Index')
            ->has('activities.data', 1)
            ->where('retention.days', ActivityLogPruner::DEFAULT_RETENTION_DAYS)
            ->has('filterOptions.logNames'),
        );
});

test('a user without the settings permission cannot view the activity log', function () {
    $viewer = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Viewer));

    $this->actingAs($viewer)
        ->get(route('activity-log.index'))
        ->assertForbidden();
});

test('the channel filter narrows the results', function () {
    logEntryAgedDays('federation', 'partner_approved', 1);
    logEntryAgedDays('users', 'user_created', 1);

    $this->actingAs(settingsAdmin())
        ->get(route('activity-log.index', ['log_name' => 'users']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.log_name', 'users'),
        );
});

test('the retention window is a persisted setting', function () {
    $this->actingAs(settingsAdmin())
        ->put(route('activity-log.retention.update'), ['retention_days' => 30])
        ->assertRedirect();

    expect(Setting::getInt(ActivityLogPruner::RETENTION_SETTING, 0))->toBe(30);
});

test('the retention window rejects negative and over-long values', function () {
    $admin = settingsAdmin();

    $this->actingAs($admin)
        ->put(route('activity-log.retention.update'), ['retention_days' => -1])
        ->assertSessionHasErrors('retention_days');

    $this->actingAs($admin)
        ->put(route('activity-log.retention.update'), ['retention_days' => 4000])
        ->assertSessionHasErrors('retention_days');
});

test('cleanup removes entries past the retention window and keeps recent ones', function () {
    $old = logEntryAgedDays('federation', 'partner_approved', 120);
    $recent = logEntryAgedDays('federation', 'partner_approved', 5);
    Setting::set(ActivityLogPruner::RETENTION_SETTING, 30);

    $this->actingAs(settingsAdmin())
        ->post(route('activity-log.prune'))
        ->assertRedirect();

    expect(Activity::find($old->id))->toBeNull()
        ->and(Activity::find($recent->id))->not->toBeNull();
});

test('a retention of zero keeps everything forever', function () {
    $old = logEntryAgedDays('federation', 'partner_approved', 900);
    Setting::set(ActivityLogPruner::RETENTION_SETTING, 0);

    expect(app(ActivityLogPruner::class)->prune())->toBe(0)
        ->and(Activity::find($old->id))->not->toBeNull();
});

test('the scheduled command prunes with the configured retention', function () {
    $old = logEntryAgedDays('federation', 'partner_approved', 120);
    $recent = logEntryAgedDays('federation', 'partner_approved', 5);
    Setting::set(ActivityLogPruner::RETENTION_SETTING, 30);

    $this->artisan('openyacht:prune-activity-log')->assertSuccessful();

    expect(Activity::find($old->id))->toBeNull()
        ->and(Activity::find($recent->id))->not->toBeNull();
});

test('a user with only the view permission can read the log but not run cleanup', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::ViewActivityLog->value);

    $this->actingAs($user)->get(route('activity-log.index'))->assertOk();

    // The destructive actions still require the settings permission.
    $this->actingAs($user)->post(route('activity-log.prune'))->assertForbidden();
    $this->actingAs($user)
        ->put(route('activity-log.retention.update'), ['retention_days' => 30])
        ->assertForbidden();
});
