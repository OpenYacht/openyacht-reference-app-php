<?php

use App\Enums\Audience;
use App\Enums\FieldGroup;
use App\Enums\ListingStatus;
use App\Enums\TrustLevel;
use App\Jobs\DeliverSubscriptionChange;
use App\Models\FederationKey;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Services\Federation\ListingSerializer;
use App\Services\Federation\SharingService;
use App\Services\Federation\SignedClient;
use App\Services\Federation\SubscriptionDeliveryFailed;
use App\Services\Federation\SubscriptionService;
use App\Services\Federation\Verifier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

// The authority half of push subscriptions (API-10): partners register a
// callback and receive, as signed POSTs, exactly what their polled feed
// would have served. // api-design.md §Subscriptions

const AUTHORITY_HOST = 'this-node.example';
const AUTHORITY_BASE = 'https://this-node.example';
const CALLBACK = 'https://consumer.example/openyacht/v1/inbox';

beforeEach(function () {
    config(['openyacht.domain' => AUTHORITY_HOST, 'openyacht.change_notifications.cooldown_minutes' => 0]);

    $this->ownKey = FederationKey::factory()->create();
    $this->partnerKeypair = federationTestKeypair();
    $this->partner = FederationPartner::factory()->verified()->create([
        'domain' => 'openyacht.partner.example',
        'keys_json' => [[
            'key_id' => $this->partnerKeypair['key_id'],
            'algorithm' => 'ed25519',
            'public_key' => $this->partnerKeypair['public_key'],
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]],
    ]);
});

/**
 * A request signed as the partner node. JSON bodies are signed over the
 * exact bytes postJson() sends (json_encode with default flags).
 */
function subscriptionRequest(object $test, string $method, string $path, ?array $body = null)
{
    $rawBody = $body === null ? '' : json_encode($body);

    $headers = federationSignedHeaders(
        $test->partner->domain,
        $test->partnerKeypair['key_id'],
        $test->partnerKeypair['secret_key'],
        $method,
        $path,
        AUTHORITY_HOST,
        $rawBody,
    );

    return $body === null
        ? $test->call($method, AUTHORITY_BASE.$path, [], [], [], collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.str_replace('-', '_', strtoupper($key)) => $value])->all())
        : $test->postJson(AUTHORITY_BASE.$path, $body, $headers);
}

function subscribePartner(FederationPartner $partner, string $callback = CALLBACK): FederationPartner
{
    $partner->update(['push_callback_url' => $callback, 'push_callback_registered_at' => now()]);

    return $partner->refresh();
}

test('a verified partner registers one callback, replacing any previous one', function () {
    subscriptionRequest($this, 'POST', '/openyacht/v1/subscriptions', ['callback' => CALLBACK])
        ->assertNoContent();

    expect($this->partner->refresh()->push_callback_url)->toBe(CALLBACK)
        ->and($this->partner->push_callback_registered_at)->not->toBeNull();

    subscriptionRequest($this, 'POST', '/openyacht/v1/subscriptions', ['callback' => 'https://consumer.example/other-inbox'])
        ->assertNoContent();

    expect($this->partner->refresh()->push_callback_url)->toBe('https://consumer.example/other-inbox')
        ->and(Activity::query()->where('event', 'subscription_registered')->count())->toBe(2);
})->group('API-10');

test('a callback must be a plain HTTPS URL on a public host', function (array $body) {
    subscriptionRequest($this, 'POST', '/openyacht/v1/subscriptions', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($this->partner->refresh()->push_callback_url)->toBeNull();
})->with([
    'missing' => [[]],
    'plain http' => [['callback' => 'http://consumer.example/inbox']],
    'loopback literal' => [['callback' => 'https://127.0.0.1/inbox']],
    'cloud metadata' => [['callback' => 'https://169.254.169.254/latest/meta-data/']],
    'non-standard port' => [['callback' => 'https://consumer.example:8443/inbox']],
    'not a string' => [['callback' => ['https://consumer.example/inbox']]],
])->group('API-10', 'FP-14', 'API-9');

test('registration requires a signature and a verified partnership', function () {
    $this->postJson(AUTHORITY_BASE.'/openyacht/v1/subscriptions', ['callback' => CALLBACK])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SIGNATURE_INVALID');

    $this->partner->update(['trust_level' => TrustLevel::Provisional]);

    subscriptionRequest($this, 'POST', '/openyacht/v1/subscriptions', ['callback' => CALLBACK])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PARTNER_PROVISIONAL');

    expect($this->partner->refresh()->push_callback_url)->toBeNull();
})->group('API-10', 'FP-6', 'FP-13');

test('DELETE removes the subscription and is idempotent', function () {
    subscribePartner($this->partner);

    subscriptionRequest($this, 'DELETE', '/openyacht/v1/subscriptions')->assertNoContent();

    expect($this->partner->refresh()->push_callback_url)->toBeNull()
        ->and($this->partner->push_callback_registered_at)->toBeNull()
        ->and(Activity::query()->where('event', 'subscription_removed')->count())->toBe(1);

    subscriptionRequest($this, 'DELETE', '/openyacht/v1/subscriptions')->assertNoContent();

    expect(Activity::query()->where('event', 'subscription_removed')->count())->toBe(1);
})->group('API-10');

test('every federation_updated_at stamp queues one delivery per subscribed, verified partner', function () {
    Queue::fake();

    $subscribed = subscribePartner($this->partner);
    $unsubscribed = FederationPartner::factory()->verified()->create();
    $provisional = subscribePartner(FederationPartner::factory()->create());

    // Published at creation: a first delivery.
    $yacht = SaleYacht::factory()->active()->create();

    Queue::assertPushed(DeliverSubscriptionChange::class, 1);
    Queue::assertPushed(DeliverSubscriptionChange::class, fn (DeliverSubscriptionChange $job) => $job->partner->is($subscribed) && $job->listingUuid === $yacht->uuid);
    Queue::assertNotPushed(DeliverSubscriptionChange::class, fn (DeliverSubscriptionChange $job) => $job->partner->is($unsubscribed) || $job->partner->is($provisional));
    Queue::assertPushedOn('notifications', DeliverSubscriptionChange::class);

    // Edits while that delivery is still queued coalesce into it — the
    // job is unique per (partner, listing) until a worker picks it up
    // and derives the payload from the current state.
    $this->travel(1)->second();
    $yacht->update(['name' => 'RENAMED']);

    Queue::assertPushed(DeliverSubscriptionChange::class, 1);

    // Once a worker has taken it, the next stamp queues a fresh delivery.
    (new UniqueLock(Cache::store()))->release(new DeliverSubscriptionChange($subscribed, $yacht->uuid));
    $this->travel(1)->second();
    $yacht->update(['name' => 'RENAMED AGAIN']);

    Queue::assertPushed(DeliverSubscriptionChange::class, 2);

    // An audience-only save neither stamps nor delivers: the visibility
    // events carry that change to exactly the affected partners.
    $yacht->audience = Audience::None;
    $yacht->save();

    Queue::assertPushed(DeliverSubscriptionChange::class, 2);

    // Drafts are never distributed (LS-7).
    SaleYacht::factory()->create()->update(['name' => 'STILL A DRAFT']);

    Queue::assertPushed(DeliverSubscriptionChange::class, 2);
})->group('API-10');

test('a visibility transition queues a delivery to the affected partner only', function () {
    $yacht = SaleYacht::factory()->active()->create();

    $kept = subscribePartner($this->partner);
    $dropped = subscribePartner(FederationPartner::factory()->verified()->create(), 'https://other.example/inbox');

    Queue::fake();

    // Narrowing to a selection the first partner is in: only the second
    // partner's view changes, so only it hears about it.
    app(SharingService::class)->setAudience($yacht, Audience::Selected, [$kept->id]);

    Queue::assertPushed(DeliverSubscriptionChange::class, 1);
    Queue::assertPushed(DeliverSubscriptionChange::class, fn (DeliverSubscriptionChange $job) => $job->partner->is($dropped) && $job->listingUuid === $yacht->uuid);
})->group('API-10');

test('a delivery carries the listing the feed would serve, signed like any federation request', function () {
    Http::fake([CALLBACK => Http::response(['status' => 'received'], 200)]);
    $partner = subscribePartner($this->partner);
    $yacht = SaleYacht::factory()->active()->create();

    // The sync queue driver already ran the delivery the creation queued.
    Http::assertSentCount(1);

    /** @var ClientRequest $request */
    $request = Http::recorded()->first()[0];

    $verification = app(Verifier::class)->verify(
        method: 'POST',
        pathWithQuery: '/openyacht/v1/inbox',
        receivingHost: 'consumer.example',
        rawBody: $request->body(),
        senderKeyId: $request->header('X-OpenYacht-Key')[0],
        timestamp: $request->header('X-OpenYacht-Timestamp')[0],
        signature: $request->header('X-OpenYacht-Signature')[0],
        publishedKeys: [$this->ownKey->key_id => $this->ownKey->public_key],
    );

    expect($request->url())->toBe(CALLBACK)
        ->and($request->hasHeader('X-OpenYacht-Node', AUTHORITY_HOST))->toBeTrue()
        ->and($verification->verified)->toBeTrue()
        ->and($request->data())->toEqual(json_decode(json_encode(
            app(ListingSerializer::class)->serialize($yacht->fresh(['vessel', 'assignedBroker', 'priceHistory', 'media']), $partner),
        ), true));

    expect($partner->refresh()->push_last_delivered_at)->not->toBeNull()
        ->and($partner->push_last_failed_at)->toBeNull();
})->group('API-10', 'FP-6');

test('unsharing delivers a tombstone at the transition and re-sharing delivers the listing again', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $partner = subscribePartner($this->partner);
    Http::fake([CALLBACK => Http::response('', 200)]);

    $this->travel(1)->minute();
    $transitionAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
    app(SharingService::class)->setAudience($yacht, Audience::None);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => $request->data() == [
        'id' => $yacht->canonicalUri(),
        'tombstone' => true,
        // Indistinguishable from a real withdrawal, timestamped at the
        // transition — never the listing's own updated_at (API-3).
        'status' => 'withdrawn',
        'updated_at' => $transitionAt,
    ]);

    $this->travel(1)->minute();
    app(SharingService::class)->setAudience($yacht, Audience::Everyone);

    Http::assertSentCount(2);
    Http::assertSent(fn (ClientRequest $request): bool => ! array_key_exists('tombstone', $request->data())
        && $request['id'] === $yacht->canonicalUri()
        && $request['status'] === 'active'
        && $request['updated_at'] === $yacht->fresh()->federation_updated_at->utc()->format('Y-m-d\TH:i:s\Z'));
})->group('API-10', 'API-3');

test('an ended listing is delivered as a tombstone with its real status', function () {
    $yacht = SaleYacht::factory()->active()->create();
    subscribePartner($this->partner);
    Http::fake([CALLBACK => Http::response('', 200)]);

    $this->travel(1)->minute();
    $yacht->transitionTo(ListingStatus::Sold);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => $request['tombstone'] === true
        && $request['status'] === 'sold'
        && $request['updated_at'] === $yacht->fresh()->federation_updated_at->utc()->format('Y-m-d\TH:i:s\Z'));
})->group('API-10', 'API-3');

test('a grant change resends the re-gated payload', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $partner = subscribePartner($this->partner);
    Http::fake([CALLBACK => Http::response('', 200)]);

    $partner->update(['field_groups' => [FieldGroup::LocationExact->value]]);
    app(SharingService::class)->refreshPartnerFeed($partner);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => $request['id'] === $yacht->canonicalUri()
        && $request['listing']['price']['amount'] === null);
})->group('API-10', 'API-4');

test('nothing is delivered for a listing the partner never saw, or once the partner unsubscribed', function () {
    Http::fake();

    // Curated partner, open-catalogue listing: not in its feed either.
    $curated = subscribePartner(FederationPartner::factory()->verified()->curated()->create());
    $yacht = SaleYacht::factory()->active()->create();

    Http::assertNothingSent();

    expect(app(SubscriptionService::class)->payloadFor($yacht, $curated))->toBeNull();

    // Queued while subscribed, run after unsubscribing: a no-op.
    $partner = subscribePartner($this->partner);
    $job = new DeliverSubscriptionChange($partner, $yacht->uuid);
    app(SubscriptionService::class)->unregister($partner);

    $job->handle(app(SubscriptionService::class), app(SignedClient::class));

    Http::assertNothingSent();
})->group('API-10', 'API-5');

test('a failing callback throws so the queue retries, on an exponential backoff inside the 24-hour window', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $partner = subscribePartner($this->partner);
    Http::fake([CALLBACK => Http::response('', 500)]);

    $job = new DeliverSubscriptionChange($partner, $yacht->uuid);
    $this->freezeSecond();

    expect(fn () => $job->handle(app(SubscriptionService::class), app(SignedClient::class)))
        ->toThrow(SubscriptionDeliveryFailed::class);

    expect($partner->refresh()->push_last_failed_at)->not->toBeNull()
        ->and($partner->push_last_delivered_at)->toBeNull();

    // One minute doubling to a four-hour cap; the queue repeats the last
    // value, so the schedule fits the 24-hour window with retries to spare.
    expect($job->backoff())->toBe([60, 120, 240, 480, 960, 1920, 3840, 7680, 14400])
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addHours(24)->getTimestamp())
        ->and($job->uniqueId())->toBe("{$partner->id}:{$yacht->uuid}");

    // A connection failure is equally a retry, not a silent drop.
    Http::fake([CALLBACK => fn () => throw new ConnectionException('refused')]);

    expect(fn () => $job->handle(app(SubscriptionService::class), app(SignedClient::class)))
        ->toThrow(ConnectionException::class);
})->group('API-10');

test('a delivery abandoned after the retry window is recorded in the activity log', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $partner = subscribePartner($this->partner);

    (new DeliverSubscriptionChange($partner, $yacht->uuid))
        ->failed(new SubscriptionDeliveryFailed('HTTP 503'));

    $entry = Activity::query()->where('event', 'push_delivery_abandoned')->sole();

    expect($entry->subject_id)->toBe($partner->id)
        ->and($entry->properties['listing_uuid'])->toBe($yacht->uuid)
        ->and($entry->properties['reason'])->toBe('HTTP 503');
})->group('API-10');

test('a blocked or downgraded partner stops receiving pushes at once', function () {
    $yacht = SaleYacht::factory()->active()->create();
    $partner = subscribePartner($this->partner);
    Http::fake();

    $partner->update(['trust_level' => TrustLevel::Blocked]);

    (new DeliverSubscriptionChange($partner, $yacht->uuid))
        ->handle(app(SubscriptionService::class), app(SignedClient::class));

    Http::assertNothingSent();
})->group('API-10', 'FP-9');
