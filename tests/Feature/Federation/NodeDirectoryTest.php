<?php

use App\Services\Federation\KeyManager;
use App\Services\Federation\NodeDirectory;
use App\Services\Federation\NodeDirectoryIndex;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function directoryDocument(array $nodes): array
{
    return [
        'registry' => 'openyacht-nodes',
        'version' => '2026.08.0',
        'canonical_url' => NodeDirectoryIndex::CANONICAL_URL,
        'nodes' => $nodes,
    ];
}

function validDirectoryNode(array $overrides = []): array
{
    return array_replace([
        'domain' => 'openyacht.partner.example',
        'name' => 'Partner Brokerage',
        'website' => 'https://partner.example',
        'country' => 'US',
        'listed_at' => '2026-08-23',
    ], $overrides);
}

beforeEach(function () {
    Storage::fake('local');
});

test('refresh caches the canonical directory and drops malformed entries', function () {
    Http::fake([
        NodeDirectoryIndex::CANONICAL_URL => Http::response(directoryDocument([
            validDirectoryNode(),
            validDirectoryNode(['domain' => 'UPPER.example', 'name' => 'Upper Brokerage']),
            validDirectoryNode(['domain' => 'not a domain']),
            validDirectoryNode(['website' => 'http://insecure.example']),
            validDirectoryNode(['country' => 'USA']),
            'not-an-entry',
        ])),
    ]);

    $index = new NodeDirectoryIndex;

    expect($index->refresh())->toBe(2)
        ->and(collect($index->entries())->pluck('domain')->all())->toBe(['openyacht.partner.example', 'upper.example'])
        ->and($index->fetchedAt())->not->toBeNull();
})->group('FP-16');

test('a failed refresh never replaces a good cache', function () {
    Http::fake([
        NodeDirectoryIndex::CANONICAL_URL => Http::sequence()
            ->push(directoryDocument([validDirectoryNode()]))
            ->push(null, 500),
    ]);

    $index = new NodeDirectoryIndex;
    $index->refresh();

    expect(fn () => $index->refresh())->toThrow(RuntimeException::class)
        ->and($index->entries())->toHaveCount(1);
})->group('FP-16');

test('a response that is not an openyacht-nodes registry document is rejected', function () {
    Http::fake([
        NodeDirectoryIndex::CANONICAL_URL => Http::response(['registry' => 'something-else', 'nodes' => []]),
    ]);

    (new NodeDirectoryIndex)->refresh();
})->throws(RuntimeException::class)->group('FP-16');

test('entries fall back to the vendored copy when nothing has been fetched', function () {
    $path = tempnam(sys_get_temp_dir(), 'nodes');
    file_put_contents($path, json_encode(directoryDocument([validDirectoryNode()])));

    $index = new NodeDirectoryIndex(vendoredPath: $path);

    expect($index->entries())->toHaveCount(1)
        ->and($index->fetchedAt())->toBeNull();

    unlink($path);
})->group('FP-16');

test('the listing token is signed by the active federation key', function () {
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    $key = app(KeyManager::class)->generate();

    $request = app(NodeDirectory::class)->listingRequest('list');

    expect($request['token'])
        ->toBe('openyacht-node-listing:v1:openyacht.this-node.example:list:'.now('UTC')->format('Y-m-d'));

    $verified = sodium_crypto_sign_verify_detached(
        base64_decode($request['signature']),
        $request['token'],
        base64_decode($key->public_key),
    );

    expect($verified)->toBeTrue();
})->group('FP-16');

test('unknown listing actions are rejected', function () {
    app(NodeDirectory::class)->listingRequest('promote');
})->throws(InvalidArgumentException::class)->group('FP-16');

test('the listing-token command prints the two lines to paste', function () {
    config(['openyacht.domain' => 'openyacht.this-node.example']);
    app(KeyManager::class)->generate();

    $this->artisan('openyacht:listing-token', ['action' => 'delist'])
        ->expectsOutputToContain('openyacht-node-listing:v1:openyacht.this-node.example:delist:')
        ->assertSuccessful();
});

test('the listing-token command fails without an active key', function () {
    config(['openyacht.domain' => 'openyacht.this-node.example']);

    $this->artisan('openyacht:listing-token')->assertFailed();
});
