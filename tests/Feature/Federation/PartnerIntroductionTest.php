<?php

use App\Enums\IntroductionOutcome;
use App\Enums\Role;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\User;
use App\Services\Federation\PartnerService;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
 * The outbound half of federation-protocol.md §Partner Lifecycle step 1:
 * adding a partner introduces this node to it with a signed partnership
 * request, so the partnership shows up on their side — with a message
 * and a contact — before any sync poll happens to arrive.
 */

const INTRODUCER = 'this-node.example';
const INTRODUCED = 'openyacht.partner.example';
const REQUEST_URL = 'https://'.INTRODUCED.'/openyacht/v1/partners/request';
const PROBE_URL = 'https://'.INTRODUCED.'/openyacht/v1/listings?page_size=1';

beforeEach(function () {
    config(['openyacht.domain' => INTRODUCER, 'openyacht.node_name' => 'This Node']);
    FederationKey::factory()->create();
    $this->seed(RoleSeeder::class);
});

function introducer(Role $role = Role::SuperAdmin): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

function introducedWellKnown(): array
{
    return [
        'openyacht' => '1.0',
        'node' => ['uuid' => '018f0000-0000-7000-8000-000000000001', 'name' => 'Partner'],
        'keys' => [['key_id' => '5e318f8cf9cbe249', 'public_key' => base64_encode(str_repeat('k', 32))]],
    ];
}

function received(string $trustLevel = 'provisional'): PromiseInterface
{
    return Http::response(['status' => 'received', 'trust_level' => $trustLevel], 202);
}

function federationError(string $code, int $status): PromiseInterface
{
    return Http::response(['error' => ['code' => $code, 'message' => $code]], $status);
}

function sentRequest(): ?Activity
{
    return Activity::query()->where('event', 'partner_request_sent')->latest('id')->first();
}

test('adding a partner delivers a signed partnership request carrying the message and contact', function () {
    Http::fake([
        INTRODUCED.'/openyacht/v1/partners/request' => received(),
        INTRODUCED.'/.well-known/openyacht' => Http::response(introducedWellKnown()),
    ]);

    $this->actingAs(introducer())
        ->post(route('partners.store'), [
            'domain' => INTRODUCED,
            'message' => 'We list in Palm Beach and would like to share.',
            'contact_email' => 'sales@this-node.example',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'info');

    $partner = FederationPartner::query()->where('domain', INTRODUCED)->firstOrFail();

    expect($partner->request_sent_at)->not->toBeNull()
        ->and(sentRequest()?->properties?->get('outcome'))->toBe(IntroductionOutcome::Delivered->value);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === REQUEST_URL
        && $request->method() === 'POST'
        && $request->hasHeader('X-OpenYacht-Signature')
        && $request->header('X-OpenYacht-Node')[0] === INTRODUCER
        && $request['message'] === 'We list in Palm Beach and would like to share.'
        && $request['contact_email'] === 'sales@this-node.example');
})->group('FP-13');

test('the add form defaults the contact to the acting user', function () {
    Http::fake([
        INTRODUCED.'/openyacht/v1/partners/request' => received(),
        INTRODUCED.'/.well-known/openyacht' => Http::response(introducedWellKnown()),
    ]);

    $actor = introducer();

    $this->actingAs($actor)
        ->post(route('partners.store'), ['domain' => INTRODUCED])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === REQUEST_URL
        && $request['contact_email'] === $actor->email);
})->group('FP-13');

test('both fields always go on the wire, defaulted from the node name and the mail from-address', function () {
    config(['mail.from.address' => 'federation@this-node.example']);
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => received()]);

    $introduction = app(PartnerService::class)->introduce($partner, '  ', null);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Delivered);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === REQUEST_URL
        && $request['message'] === 'This Node would like to federate listings with you via OpenYacht.'
        && $request['contact_email'] === 'federation@this-node.example');
})->group('FP-13');

test('a partner that already lists this node as verified reports the request as accepted', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => received('verified')]);

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Accepted)
        ->and($partner->refresh()->request_sent_at)->not->toBeNull();
})->group('FP-13');

test('a provisional refusal from the request endpoint still counts as delivered', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => federationError('PARTNER_PROVISIONAL', 403)]);

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Delivered)
        ->and($partner->refresh()->request_sent_at)->not->toBeNull();
})->group('FP-13');

test('a node without the request endpoint is introduced through a signed listings probe', function (int $status) {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([
        INTRODUCED.'/openyacht/v1/partners/request' => Http::response('Not here', $status),
        INTRODUCED.'/openyacht/v1/listings*' => federationError('PARTNER_PROVISIONAL', 403),
    ]);

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Delivered)
        ->and($partner->refresh()->request_sent_at)->not->toBeNull();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === PROBE_URL
        && $request->method() === 'GET'
        && $request->hasHeader('X-OpenYacht-Signature'));
})->with(['404' => 404, '405' => 405])->group('FP-13');

test('a listings probe that is answered with listings means the partner already trusts this node', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([
        INTRODUCED.'/openyacht/v1/partners/request' => Http::response('', 404),
        INTRODUCED.'/openyacht/v1/listings*' => Http::response(['data' => [], 'meta' => []]),
    ]);

    expect(app(PartnerService::class)->introduce($partner)->outcome)->toBe(IntroductionOutcome::Accepted);
})->group('FP-13');

test('a partner that has blocked this node is reported as blocked and nothing is stamped', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => federationError('PARTNER_BLOCKED', 403)]);

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Blocked)
        ->and($partner->refresh()->request_sent_at)->toBeNull()
        ->and(sentRequest()?->properties?->get('outcome'))->toBe('blocked');
})->group('FP-13');

test('an unexpected answer keeps the partner but reports an undelivered request', function () {
    Http::fake([
        INTRODUCED.'/openyacht/v1/partners/request' => Http::response('', 500),
        INTRODUCED.'/.well-known/openyacht' => Http::response(introducedWellKnown()),
    ]);

    $this->actingAs(introducer())
        ->post(route('partners.store'), ['domain' => INTRODUCED])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'error');

    $partner = FederationPartner::query()->where('domain', INTRODUCED)->firstOrFail();

    expect($partner->request_sent_at)->toBeNull()
        ->and(sentRequest()?->properties?->get('outcome'))->toBe('failed')
        ->and(sentRequest()?->properties?->get('detail'))->toContain('HTTP 500');
})->group('FP-13');

test('an unreachable partner is reported as undelivered, not thrown', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => Http::failedConnection()]);

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Failed)
        ->and($introduction->message)->toContain(INTRODUCED)
        ->and($partner->refresh()->request_sent_at)->toBeNull();
})->group('FP-13');

test('a node without an active signing key cannot introduce itself and sends nothing', function () {
    FederationKey::query()->delete();
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake();

    $introduction = app(PartnerService::class)->introduce($partner);

    expect($introduction->outcome)->toBe(IntroductionOutcome::Failed)
        ->and($introduction->message)->toContain('openyacht:install');

    Http::assertNothingSent();
})->group('FP-13');

test('the outbound guard refuses to introduce this node to a non-public host', function () {
    $partner = FederationPartner::factory()->create(['domain' => 'localhost']);
    Http::fake();

    expect(app(PartnerService::class)->introduce($partner)->outcome)->toBe(IntroductionOutcome::Failed);

    Http::assertNothingSent();
})->group('FP-13', 'FP-14');

test('the partnership request can be re-sent from the partner page', function () {
    $partner = FederationPartner::factory()->create(['domain' => INTRODUCED]);
    Http::fake([INTRODUCED.'/openyacht/v1/partners/request' => received('verified')]);

    $this->actingAs(introducer(Role::Admin))
        ->post(route('partners.introduce', $partner))
        ->assertForbidden();

    $actor = introducer();

    $this->actingAs($actor)
        ->post(route('partners.introduce', $partner), ['message' => 'Still keen to federate.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKey::FLASH_DATA.'.toast.type', 'success');

    expect($partner->refresh()->request_sent_at)->not->toBeNull();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === REQUEST_URL
        && $request['message'] === 'Still keen to federate.'
        && $request['contact_email'] === $actor->email);
})->group('FP-13');

test('a partner blocked here is not sent a partnership request', function () {
    $partner = FederationPartner::factory()->blocked()->create(['domain' => INTRODUCED]);
    Http::fake();

    $this->actingAs(introducer())
        ->from(route('partners.show', $partner))
        ->post(route('partners.introduce', $partner))
        ->assertRedirect(route('partners.show', $partner))
        ->assertSessionHasErrors('partner');

    Http::assertNothingSent();
})->group('FP-13');

test('the partner page shows both directions of the request', function () {
    $partner = FederationPartner::factory()->create([
        'request_sent_at' => now()->subHour(),
        'requested_at' => now()->subMinutes(5),
        'request_message' => 'Hello from the other side.',
        'request_contact_email' => 'broker@partner.example',
    ]);

    $this->actingAs(introducer())
        ->get(route('partners.show', $partner))
        ->assertInertia(fn (Assert $page) => $page
            ->where('partner.request_message', 'Hello from the other side.')
            ->where('partner.request_contact_email', 'broker@partner.example')
            ->whereNot('partner.requested_at', null)
            ->whereNot('partner.request_sent_at', null));
})->group('FP-13');
