<?php

use App\Support\ApiDocs\ApiDocGenerator;

test('the generated yacht api guide serves as markdown with code-derived tables', function () {
    $response = $this->get('/docs/api/yachts.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

    $markdown = $response->getContent();

    expect($markdown)
        // Narrative from the intro file.
        ->toContain('# Yacht Data API')
        ->toContain('listing-schema.md')
        // Endpoint headings from the manifest.
        ->toContain('### `GET /api/v1/yachts`')
        ->toContain('### `GET /api/v1/yachts/{key}`')
        ->toContain('**Required scope:** `yachts:read`')
        // Parameter rows reflected from the controller attributes.
        ->toContain('`price_currency`')
        ->toContain('`price_min`')
        ->toContain('ECB')
        // Local extension fields, but never the spec's own field tables.
        ->toContain('`x_provenance`')
        ->toContain('`x_attribution_text`');
})->group('demo-node');

test('an unknown guide is a 404, and the generator refuses it loudly', function () {
    $this->get('/docs/api/nope.md')->assertNotFound();

    expect(fn () => app(ApiDocGenerator::class)->generate('nope'))
        ->toThrow(InvalidArgumentException::class);
})->group('demo-node');

test('the openapi spec is served alongside the guide', function () {
    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonStructure(['openapi', 'paths']);
})->group('demo-node');
