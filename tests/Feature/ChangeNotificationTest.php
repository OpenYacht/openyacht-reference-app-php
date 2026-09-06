<?php

use App\Enums\Audience;
use App\Jobs\SendChangeNotification;
use App\Models\SaleYacht;
use App\Models\WebhookEndpoint;
use App\Services\ChangeNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['openyacht.change_notifications.cooldown_minutes' => 0]);
});

test('nothing is sent when no endpoint is active', function () {
    WebhookEndpoint::factory()->inactive()->create();
    Queue::fake();

    expect(app(ChangeNotifier::class)->notify('sync:partner.example 1 created'))->toBeFalse();

    Queue::assertNothingPushed();
})->group('demo-node');

test('one job is queued per active endpoint, once per cooldown window, and force bypasses the debounce', function () {
    config(['openyacht.change_notifications.cooldown_minutes' => 15]);
    [$first, $second] = WebhookEndpoint::factory()->count(2)->create();
    WebhookEndpoint::factory()->inactive()->create();
    Queue::fake();

    $notifier = app(ChangeNotifier::class);

    expect($notifier->notify('first'))->toBeTrue()
        ->and($notifier->notify('debounced'))->toBeFalse()
        ->and($notifier->notify('forced', force: true))->toBeTrue();

    Queue::assertPushed(SendChangeNotification::class, 4);
    Queue::assertPushedOn(SendChangeNotification::QUEUE, SendChangeNotification::class);
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($first) && $job->reason === 'first');
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($second) && $job->reason === 'forced');
})->group('demo-node');

test('the delivery job posts the minimal body with the endpoint\'s own secret header and records the delivery', function () {
    $endpoint = WebhookEndpoint::factory()->withSecret('shhh')->create(['url' => 'https://consumer.example/hook']);
    Http::fake(['consumer.example/*' => Http::response('', 204)]);

    (new SendChangeNotification($endpoint, 'sync:partner.example 3 created, 1 updated, 0 tombstoned', ['created' => 3, 'updated' => 1]))->handle();

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://consumer.example/hook'
            && $request->hasHeader('X-OpenYacht-Webhook-Secret', 'shhh')
            && $request['reason'] === 'sync:partner.example 3 created, 1 updated, 0 tombstoned'
            && $request['counts'] === ['created' => 3, 'updated' => 1]
            && is_string($request['timestamp']);
    });

    $endpoint->refresh();
    $delivery = $endpoint->deliveries()->sole();

    expect($delivery->succeeded)->toBeTrue()
        ->and($delivery->http_status)->toBe(204)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->reason)->toBe('sync:partner.example 3 created, 1 updated, 0 tombstoned')
        ->and($endpoint->last_succeeded_at)->not->toBeNull()
        ->and($endpoint->consecutive_failures)->toBe(0);
})->group('demo-node');

test('an endpoint without a secret is posted to without the header', function () {
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://consumer.example/hook']);
    Http::fake();

    (new SendChangeNotification($endpoint, 'reason'))->handle();

    Http::assertSent(fn ($request) => ! $request->hasHeader('X-OpenYacht-Webhook-Secret'));
})->group('demo-node');

test('a failing consumer is recorded as a failure, never thrown', function () {
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://consumer.example/hook']);
    Http::fake(['consumer.example/*' => Http::response('', 500)]);

    (new SendChangeNotification($endpoint, 'reason'))->handle();

    Http::assertSentCount(1);

    $endpoint->refresh();
    $delivery = $endpoint->deliveries()->sole();

    expect($delivery->succeeded)->toBeFalse()
        ->and($delivery->http_status)->toBe(500)
        ->and($delivery->error)->toBe('HTTP 500')
        ->and($endpoint->last_failed_at)->not->toBeNull()
        ->and($endpoint->consecutive_failures)->toBe(1);
})->group('demo-node');

test('a connection error is recorded with its message and a success resets the failure streak', function () {
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://consumer.example/hook']);
    $calls = 0;
    Http::fake(['consumer.example/*' => function () use (&$calls) {
        if (++$calls <= 2) {
            throw new ConnectionException('Could not resolve host');
        }

        return Http::response('', 200);
    }]);

    (new SendChangeNotification($endpoint, 'reason'))->handle();
    (new SendChangeNotification($endpoint, 'reason'))->handle();

    expect($endpoint->refresh()->consecutive_failures)->toBe(2)
        ->and($endpoint->deliveries()->latest('id')->first()->error)->toBe('Could not resolve host')
        ->and($endpoint->deliveries()->latest('id')->first()->http_status)->toBeNull();

    (new SendChangeNotification($endpoint, 'reason'))->handle();

    expect($endpoint->refresh()->consecutive_failures)->toBe(0)
        ->and($endpoint->deliveries()->count())->toBe(3);
})->group('demo-node');

test('the per-endpoint delivery log is bounded', function () {
    $endpoint = WebhookEndpoint::factory()->create();

    foreach (range(1, WebhookEndpoint::DELIVERY_LOG_SIZE + 5) as $index) {
        $endpoint->recordDelivery("reason {$index}", 1, true, 200, null, 10);
    }

    expect($endpoint->deliveries()->count())->toBe(WebhookEndpoint::DELIVERY_LOG_SIZE)
        ->and($endpoint->deliveries()->oldest('id')->first()->reason)->toBe('reason 6');
})->group('demo-node');

test('editing a publicly served listing notifies; drafts and audience-only changes stay quiet', function () {
    WebhookEndpoint::factory()->create();
    Queue::fake();

    $draft = SaleYacht::factory()->create();
    Queue::assertNothingPushed();

    $active = SaleYacht::factory()->active()->create();
    Queue::assertPushed(SendChangeNotification::class, 1);

    $active->update(['price_amount' => '123456']);
    Queue::assertPushed(SendChangeNotification::class, 2);

    $active->forceFill(['audience' => Audience::None])->save();
    $draft->update(['summary' => 'Still a draft.']);
    Queue::assertPushed(SendChangeNotification::class, 2);
})->group('demo-node');

test('the artisan trigger sends immediately and reports when no endpoint is active', function () {
    WebhookEndpoint::factory()->create();
    Queue::fake();

    $this->artisan('openyacht:notify-change', ['--reason' => 'deploy'])
        ->assertSuccessful();

    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->reason === 'deploy');

    WebhookEndpoint::query()->update(['is_active' => false]);

    $this->artisan('openyacht:notify-change')->assertFailed();
})->group('demo-node');

test('every ping stamps last_notified_at, which the scheduled floor measures from', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    Queue::fake();

    expect($endpoint->last_notified_at)->toBeNull();

    app(ChangeNotifier::class)->notify('sync:partner.example 1 created');

    expect($endpoint->refresh()->last_notified_at)->not->toBeNull();
})->group('demo-node');

test('the scheduled ping fires only once the interval has elapsed since the last ping of any kind', function () {
    Queue::fake();
    $this->travelTo(now()->startOfHour());

    $daily = WebhookEndpoint::factory()->scheduledEvery(1440)->create();
    $hourly = WebhookEndpoint::factory()->scheduledEvery(60)->create();
    $unscheduled = WebhookEndpoint::factory()->create();
    $disabled = WebhookEndpoint::factory()->inactive()->scheduledEvery(60)->create();

    $notifier = app(ChangeNotifier::class);

    expect($notifier->notifyScheduled())->toBe(2);
    Queue::assertPushed(SendChangeNotification::class, 2);
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($daily) && $job->reason === 'scheduled:every 24 h');
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($hourly) && $job->reason === 'scheduled:every 1 h');
    Queue::assertNotPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($unscheduled) || $job->endpoint->is($disabled));

    $this->travel(30)->minutes();
    expect($notifier->notifyScheduled())->toBe(0);

    $this->travel(30)->minutes();
    expect($notifier->notifyScheduled())->toBe(1);
    Queue::assertPushed(SendChangeNotification::class, 3);

    $this->travel(23)->hours();
    $notifier->notify('sync:partner.example 1 updated');
    Queue::assertPushed(SendChangeNotification::class, 6);

    $this->travel(1)->hours();
    expect($notifier->notifyScheduled())->toBe(1);
    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->endpoint->is($daily) && str_starts_with($job->reason, 'scheduled:'), 1);

    $this->travel(23)->hours();
    expect($notifier->notifyScheduled())->toBe(2);
})->group('demo-node');

test('a tick a few seconds short of the interval still counts as due', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->scheduledEvery(1440)->create(['last_notified_at' => now()->subMinutes(1440)->addSeconds(30)]);

    expect($endpoint->isScheduledPingDue())->toBeTrue();

    $endpoint->forceFill(['last_notified_at' => now()->subMinutes(1440 - WebhookEndpoint::SCHEDULE_TOLERANCE_MINUTES - 1)])->save();

    expect($endpoint->isScheduledPingDue())->toBeFalse();
})->group('demo-node');

test('the scheduled artisan command is registered hourly and reports what it queued', function () {
    Queue::fake();
    WebhookEndpoint::factory()->scheduledEvery(60)->create();

    $this->artisan('openyacht:notify-scheduled')
        ->expectsOutputToContain('queued to 1 endpoint')
        ->assertSuccessful();

    $this->artisan('openyacht:notify-scheduled')
        ->expectsOutputToContain('No scheduled notifications are due')
        ->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'openyacht:notify-scheduled'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
})->group('demo-node');
