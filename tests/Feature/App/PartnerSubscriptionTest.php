<?php

use App\Enums\Role;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

// The operator's subscribe/unsubscribe on the partner page — the
// consumer half's wire side (API-11, with the API-7 capability check).

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    FederationKey::factory()->create();

    $this->partner = FederationPartner::factory()->verified()->create(['domain' => 'openyacht.partner.example']);
});

function subscriptionActor(Role $role = Role::SuperAdmin): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

function fakePartnerAdvertising(bool $subscriptions, int $registrationStatus = 204, array $registrationBody = []): void
{
    Http::fake([
        'openyacht.partner.example/openyacht/v1/capabilities' => Http::response([
            'protocol_versions' => ['1.0'],
            'features' => ['subscriptions' => $subscriptions, 'charter_listings' => true, 'media_hashes' => true],
        ]),
        'openyacht.partner.example/openyacht/v1/subscriptions' => Http::response($registrationBody === [] ? '' : $registrationBody, $registrationStatus),
    ]);
}

test('subscribing checks the partner advertises the feature, then registers this node\'s inbox with a signed request', function () {
    fakePartnerAdvertising(subscriptions: true);

    $this->actingAs(subscriptionActor())
        ->post(route('partners.subscribe', $this->partner))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://openyacht.partner.example/openyacht/v1/subscriptions'
        && $request->hasHeader('X-OpenYacht-Node', 'openyacht.this-node.example')
        && $request->hasHeader('X-OpenYacht-Signature')
        && $request['callback'] === 'https://openyacht.this-node.example/openyacht/v1/inbox');

    expect($this->partner->refresh()->push_subscribed_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'push_subscribed')->count())->toBe(1);
})->group('API-11', 'API-7');

test('a partner that does not advertise subscriptions is left to polling', function () {
    fakePartnerAdvertising(subscriptions: false);

    $this->actingAs(subscriptionActor())
        ->from(route('partners.show', $this->partner))
        ->post(route('partners.subscribe', $this->partner))
        ->assertRedirect(route('partners.show', $this->partner))
        ->assertSessionHasErrors('partner');

    Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/subscriptions'));

    expect($this->partner->refresh()->push_subscribed_at)->toBeNull();
})->group('API-7');

test('only a verified partner can be subscribed to, because the inbox accepts only verified senders', function () {
    Http::fake();
    $provisional = FederationPartner::factory()->create();

    $this->actingAs(subscriptionActor())
        ->from(route('partners.show', $provisional))
        ->post(route('partners.subscribe', $provisional))
        ->assertRedirect(route('partners.show', $provisional))
        ->assertSessionHasErrors('partner');

    Http::assertNothingSent();
})->group('API-11', 'FP-13');

test('a partner refusing the registration leaves the subscription unset and says why', function () {
    fakePartnerAdvertising(subscriptions: true, registrationStatus: 403, registrationBody: ['error' => ['code' => 'PARTNER_PROVISIONAL', 'message' => 'pending']]);

    $this->actingAs(subscriptionActor())
        ->from(route('partners.show', $this->partner))
        ->post(route('partners.subscribe', $this->partner))
        ->assertRedirect(route('partners.show', $this->partner))
        ->assertSessionHasErrors('partner');

    expect(session('errors')->first('partner'))->toContain('PARTNER_PROVISIONAL')
        ->and($this->partner->refresh()->push_subscribed_at)->toBeNull();
})->group('API-11');

test('an unreachable partner surfaces as an error, not an exception', function () {
    Http::fake([
        'openyacht.partner.example/openyacht/v1/capabilities' => Http::response(['features' => ['subscriptions' => true]]),
        'openyacht.partner.example/openyacht/v1/subscriptions' => fn () => throw new ConnectionException('timed out'),
    ]);

    $this->actingAs(subscriptionActor())
        ->from(route('partners.show', $this->partner))
        ->post(route('partners.subscribe', $this->partner))
        ->assertRedirect(route('partners.show', $this->partner))
        ->assertSessionHasErrors('partner');

    expect($this->partner->refresh()->push_subscribed_at)->toBeNull();
})->group('API-11');

test('unsubscribing sends a signed DELETE and clears the record', function () {
    $this->partner->update(['push_subscribed_at' => now()]);
    Http::fake(['openyacht.partner.example/openyacht/v1/subscriptions' => Http::response('', 204)]);

    $this->actingAs(subscriptionActor())
        ->delete(route('partners.unsubscribe', $this->partner))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://openyacht.partner.example/openyacht/v1/subscriptions'
        && $request->hasHeader('X-OpenYacht-Signature'));

    expect($this->partner->refresh()->push_subscribed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'push_unsubscribed')->count())->toBe(1);
})->group('API-11');

test('a partner that no longer serves the endpoint counts as unsubscribed; any other failure keeps the record', function () {
    $this->partner->update(['push_subscribed_at' => now()]);

    Http::fake(['openyacht.partner.example/openyacht/v1/subscriptions' => Http::sequence()->push('', 500)->push('', 404)]);

    $this->actingAs(subscriptionActor())
        ->from(route('partners.show', $this->partner))
        ->delete(route('partners.unsubscribe', $this->partner))
        ->assertRedirect(route('partners.show', $this->partner))
        ->assertSessionHasErrors('partner');

    expect($this->partner->refresh()->push_subscribed_at)->not->toBeNull();

    $this->actingAs(subscriptionActor())
        ->delete(route('partners.unsubscribe', $this->partner))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->partner->refresh()->push_subscribed_at)->toBeNull();
})->group('API-11');

test('subscription actions require the federation permission', function () {
    Http::fake();

    $this->actingAs(subscriptionActor(Role::Admin))
        ->post(route('partners.subscribe', $this->partner))
        ->assertForbidden();

    $this->actingAs(subscriptionActor(Role::Admin))
        ->delete(route('partners.unsubscribe', $this->partner))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the partner page shows both directions of the subscription', function () {
    $this->partner->forceFill([
        'push_subscribed_at' => now()->subHour(),
        'push_callback_url' => 'https://openyacht.partner.example/openyacht/v1/inbox',
        'push_callback_registered_at' => now()->subDay(),
        'push_last_delivered_at' => now()->subMinutes(5),
    ])->save();

    $this->actingAs(subscriptionActor())
        ->get(route('partners.show', $this->partner))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('federation/partners/Show')
            ->where('partner.push_callback_url', 'https://openyacht.partner.example/openyacht/v1/inbox')
            ->whereNot('partner.push_subscribed_at', null)
            ->whereNot('partner.push_callback_registered_at', null)
            ->whereNot('partner.push_last_delivered_at', null)
            ->where('partner.push_last_failed_at', null));
});
