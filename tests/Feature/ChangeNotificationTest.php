<?php

use App\Enums\Audience;
use App\Jobs\SendChangeNotification;
use App\Models\SaleYacht;
use App\Services\ChangeNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'openyacht.change_notifications.urls' => ['https://consumer.example/hook'],
        'openyacht.change_notifications.cooldown_minutes' => 0,
    ]);
});

test('nothing is sent when no urls are configured', function () {
    config(['openyacht.change_notifications.urls' => []]);
    Queue::fake();

    expect(app(ChangeNotifier::class)->notify('sync:partner.example 1 created'))->toBeFalse();

    Queue::assertNothingPushed();
})->group('demo-node');

test('a notification is queued once per cooldown window, and force bypasses the debounce', function () {
    config(['openyacht.change_notifications.cooldown_minutes' => 15]);
    Queue::fake();

    $notifier = app(ChangeNotifier::class);

    expect($notifier->notify('first'))->toBeTrue()
        ->and($notifier->notify('debounced'))->toBeFalse()
        ->and($notifier->notify('forced', force: true))->toBeTrue();

    Queue::assertPushed(SendChangeNotification::class, 2);
})->group('demo-node');

test('the delivery job posts the minimal body to every url with the shared secret header', function () {
    config([
        'openyacht.change_notifications.urls' => ['https://consumer.example/hook', 'https://other.example/hook'],
        'openyacht.change_notifications.secret' => 'shhh',
    ]);
    Http::fake();

    (new SendChangeNotification('sync:partner.example 3 created, 1 updated, 0 tombstoned', ['created' => 3, 'updated' => 1]))->handle();

    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://consumer.example/hook'
            && $request->hasHeader('X-OpenYacht-Webhook-Secret', 'shhh')
            && $request['reason'] === 'sync:partner.example 3 created, 1 updated, 0 tombstoned'
            && $request['counts'] === ['created' => 3, 'updated' => 1]
            && is_string($request['timestamp']);
    });
})->group('demo-node');

test('a failing consumer url is logged, never thrown', function () {
    Http::fake(['consumer.example/*' => Http::response('', 500)]);

    (new SendChangeNotification('reason'))->handle();

    Http::assertSentCount(1);
})->group('demo-node');

test('editing a publicly served listing notifies; drafts and audience-only changes stay quiet', function () {
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
    Queue::assertPushed(SendChangeNotification::class, 2);
})->group('demo-node');

test('the artisan trigger sends immediately and reports when unconfigured', function () {
    Queue::fake();

    $this->artisan('openyacht:notify-change', ['--reason' => 'deploy'])
        ->assertSuccessful();

    Queue::assertPushed(SendChangeNotification::class, fn (SendChangeNotification $job) => $job->reason === 'deploy');

    config(['openyacht.change_notifications.urls' => []]);

    $this->artisan('openyacht:notify-change')->assertFailed();
})->group('demo-node');
