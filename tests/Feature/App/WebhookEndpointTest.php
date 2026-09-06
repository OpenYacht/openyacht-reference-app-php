<?php

use App\Enums\Role;
use App\Jobs\SendChangeNotification;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function webhookAdmin(): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Admin));
}

test('the webhooks page lists endpoints with their recent deliveries and never the secret', function () {
    $endpoint = WebhookEndpoint::factory()->withSecret('top-secret')->create();
    $endpoint->recordDelivery('test', 1, false, 503, 'HTTP 503', 120);
    $endpoint->recordDelivery('test', 2, true, 200, null, 80);

    $response = $this->actingAs(webhookAdmin())
        ->get(route('webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Index')
            ->has('endpoints', 1)
            ->where('endpoints.0.name', $endpoint->name)
            ->where('endpoints.0.url', $endpoint->url)
            ->where('endpoints.0.has_secret', true)
            ->where('endpoints.0.consecutive_failures', 0)
            ->has('endpoints.0.deliveries', 2)
            ->where('endpoints.0.deliveries.0.succeeded', true)
            ->where('endpoints.0.deliveries.1.error', 'HTTP 503')
            ->where('cooldownMinutes', (int) config('openyacht.change_notifications.cooldown_minutes')),
        );

    expect($response->getContent())->not->toContain('top-secret');
})->group('demo-node');

test('the webhooks page requires the settings permission', function () {
    $viewer = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Viewer));

    $this->actingAs($viewer)->get(route('webhooks.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('webhooks.store'), ['name' => 'x', 'url' => 'https://x.example/hook'])->assertForbidden();
})->group('demo-node');

test('an endpoint is created with an encrypted secret and an http(s) url', function () {
    $admin = webhookAdmin();

    $this->actingAs($admin)
        ->post(route('webhooks.store'), [
            'name' => 'Marketing site',
            'url' => 'https://marketing.example/hooks/openyacht',
            'secret' => 'shhh',
        ])
        ->assertRedirect(route('webhooks.index'));

    $endpoint = WebhookEndpoint::query()->sole();

    expect($endpoint->secret)->toBe('shhh')
        ->and($endpoint->is_active)->toBeTrue()
        ->and($endpoint->created_by_user_id)->toBe($admin->id)
        ->and(DB::table('webhook_endpoints')->value('secret'))->not->toBe('shhh');

    $this->actingAs($admin)
        ->post(route('webhooks.store'), ['name' => 'Bad', 'url' => 'ftp://files.example/hook'])
        ->assertSessionHasErrors('url');

    $this->actingAs($admin)
        ->post(route('webhooks.store'), ['name' => 'Bad', 'url' => 'not a url'])
        ->assertSessionHasErrors('url');
})->group('demo-node');

test('a blank secret stores null', function () {
    $this->actingAs(webhookAdmin())
        ->post(route('webhooks.store'), ['name' => 'Open', 'url' => 'https://open.example/hook', 'secret' => ''])
        ->assertRedirect();

    expect(WebhookEndpoint::query()->sole()->secret)->toBeNull();
})->group('demo-node');

test('editing keeps the secret when blank, replaces it when given, and clears it on request', function () {
    $admin = webhookAdmin();
    $endpoint = WebhookEndpoint::factory()->withSecret('original')->create();

    $this->actingAs($admin)
        ->put(route('webhooks.update', $endpoint), ['name' => 'Renamed', 'url' => 'https://new.example/hook', 'secret' => ''])
        ->assertRedirect();

    expect($endpoint->refresh())
        ->name->toBe('Renamed')
        ->url->toBe('https://new.example/hook')
        ->secret->toBe('original');

    $this->actingAs($admin)
        ->put(route('webhooks.update', $endpoint), ['name' => 'Renamed', 'url' => 'https://new.example/hook', 'secret' => 'rotated'])
        ->assertRedirect();

    expect($endpoint->refresh()->secret)->toBe('rotated');

    $this->actingAs($admin)
        ->put(route('webhooks.update', $endpoint), ['name' => 'Renamed', 'url' => 'https://new.example/hook', 'secret' => '', 'clear_secret' => true])
        ->assertRedirect();

    expect($endpoint->refresh()->secret)->toBeNull();
})->group('demo-node');

test('an endpoint can be disabled and re-enabled from the list', function () {
    $admin = webhookAdmin();
    $endpoint = WebhookEndpoint::factory()->create();

    $this->actingAs($admin)->put(route('webhooks.update', $endpoint), ['is_active' => false])->assertRedirect();
    expect($endpoint->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->put(route('webhooks.update', $endpoint), ['is_active' => true])->assertRedirect();
    expect($endpoint->refresh()->is_active)->toBeTrue();
})->group('demo-node');

test('deleting an endpoint removes its delivery log with it', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $endpoint->recordDelivery('test', 1, true, 200, null, 50);

    $this->actingAs(webhookAdmin())
        ->delete(route('webhooks.destroy', $endpoint))
        ->assertRedirect();

    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(DB::table('webhook_deliveries')->count())->toBe(0);
})->group('demo-node');

test('the test button queues a ping to that endpoint alone, even while disabled', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->inactive()->create();
    WebhookEndpoint::factory()->create();

    $this->actingAs(webhookAdmin())
        ->post(route('webhooks.test', $endpoint))
        ->assertRedirect();

    Queue::assertPushed(SendChangeNotification::class, 1);
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($endpoint) && $job->reason === 'test');
})->group('demo-node');

test('an endpoint can be created and edited with a scheduled ping interval within one hour and one week', function () {
    $admin = webhookAdmin();

    $this->actingAs($admin)
        ->post(route('webhooks.store'), ['name' => 'Site', 'url' => 'https://site.example/hook', 'schedule_interval_minutes' => 1440])
        ->assertRedirect(route('webhooks.index'));

    $endpoint = WebhookEndpoint::query()->sole();
    expect($endpoint->schedule_interval_minutes)->toBe(1440);

    $this->actingAs($admin)
        ->put(route('webhooks.update', $endpoint), ['name' => 'Site', 'url' => 'https://site.example/hook', 'schedule_interval_minutes' => null])
        ->assertRedirect();
    expect($endpoint->refresh()->schedule_interval_minutes)->toBeNull();

    $this->actingAs($admin)
        ->put(route('webhooks.update', $endpoint), ['is_active' => false])
        ->assertRedirect();
    expect($endpoint->refresh()->schedule_interval_minutes)->toBeNull();

    foreach ([30, 10081, 'daily'] as $invalid) {
        $this->actingAs($admin)
            ->post(route('webhooks.store'), ['name' => 'Bad', 'url' => 'https://bad.example/hook', 'schedule_interval_minutes' => $invalid])
            ->assertSessionHasErrors('schedule_interval_minutes');
    }

    $this->actingAs($admin)
        ->get(route('webhooks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('endpoints.0.schedule_interval_minutes', null)
            ->where('endpoints.0.last_notified_at', null)
            ->where('schedulePresets', [60, 360, 720, 1440, 10080]),
        );
})->group('demo-node');
