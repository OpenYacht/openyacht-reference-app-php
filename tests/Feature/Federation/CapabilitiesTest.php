<?php

beforeEach(function () {
    config(['openyacht.domain' => 'openyacht.example.test']);
});

test('capabilities is served unsigned with protocol versions, features, and limits', function () {
    $this->get('https://openyacht.example.test/openyacht/v1/capabilities')
        ->assertOk()
        ->assertJson([
            'protocol_versions' => ['1.0'],
            'features' => [
                // The optional push layer is implemented (API-10).
                'subscriptions' => true,
                // charter_listings governs implementing the charter block
                // of the wire schema, not inventory held (api-design.md).
                'charter_listings' => true,
                'media_hashes' => true,
            ],
            'limits' => [
                'page_size_max' => 100,
                'rate_per_hour' => 500,
            ],
        ]);
})->group('API-6', 'API-10');

test('health is served unsigned', function () {
    $this->get('https://openyacht.example.test/openyacht/v1/health')
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'time']);
})->group('API-6');
