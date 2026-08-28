<?php

use App\Services\Federation\BlockedOutboundHost;
use App\Services\Federation\InvalidWellKnownDocument;
use App\Services\Federation\OutboundUrlGuard;
use App\Services\Federation\PartnerService;
use Illuminate\Support\Facades\Http;

// The outbound guard is the SSRF chokepoint for every federation fetch:
// well-known discovery, signed sync, and media import all pass their
// host through it. // federation-protocol.md §Untrusted Input (FP-14)

$guard = fn (): OutboundUrlGuard => app(OutboundUrlGuard::class);

test('the guard rejects the SSRF host shapes an attacker would use', function (string $host) use ($guard) {
    expect(fn () => $guard()->assertPublicHost($host))
        ->toThrow(BlockedOutboundHost::class);
})->with([
    'ipv4 loopback' => ['127.0.0.1'],
    'cloud metadata' => ['169.254.169.254'],
    'ipv6 loopback' => ['::1'],
    'private range' => ['10.0.0.5'],
    'no dot / localhost' => ['localhost'],
    'trailing path' => ['evil.example/path'],
    'embedded port' => ['evil.example:8080'],
    'userinfo trick' => ['evil.example@127.0.0.1'],
])->group('FP-14');

test('the guard rejects non-https and host-smuggling URLs', function (string $url) use ($guard) {
    expect(fn () => $guard()->assertPublicHttpsUrl($url))
        ->toThrow(BlockedOutboundHost::class);
})->with([
    'plain http' => ['http://partner.example/x'],
    'file scheme' => ['file:///etc/passwd'],
    'gopher scheme' => ['gopher://127.0.0.1:6379/x'],
    'ip literal host' => ['https://169.254.169.254/latest/meta-data/'],
    'internal port' => ['https://127.0.0.1:6379/x'],
    'userinfo host' => ['https://partner.example@127.0.0.1/x'],
])->group('FP-14');

test('adding a partner by an internal address makes no outbound request', function () {
    Http::fake();

    expect(fn () => app(PartnerService::class)->add('169.254.169.254'))
        ->toThrow(InvalidWellKnownDocument::class);

    Http::assertNothingSent();
})->group('FP-14');

test('a redirect on the well-known fetch is not followed and yields no document', function () {
    // withoutRedirecting means a 3xx comes back as-is rather than being
    // chased to a private host; an empty body is not a valid document.
    Http::fake([
        'openyacht.partner.example/.well-known/openyacht' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
    ]);

    expect(fn () => app(PartnerService::class)->add('openyacht.partner.example'))
        ->toThrow(InvalidWellKnownDocument::class);

    Http::assertSent(fn ($request) => $request->url() === 'https://openyacht.partner.example/.well-known/openyacht');
})->group('FP-2', 'FP-14');
