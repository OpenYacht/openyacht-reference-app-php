<?php

use App\Http\Controllers\Api\V1\YachtsController;

/*
|--------------------------------------------------------------------------
| Agent-facing API documentation manifest
|--------------------------------------------------------------------------
|
| Drives the generated Markdown guide served at /docs/api/{guide}.md.
| Endpoint structure is declared here (it is stable); summaries,
| descriptions and query-parameter tables are read live from the
| controllers via reflection (App\Support\ApiDocs\ApiDocGenerator), so
| they never drift from the API.
|
| Field tables cover only what is local to this surface — the response
| envelope and the x_ extension fields. The listing document itself is
| the protocol wire schema and is documented normatively by the spec
| (listing-schema.md); it is deliberately not duplicated here.
|
*/

$extensionFields = [
    ['name' => 'x_key', 'type' => 'string', 'description' => 'Stable identifier on this node: the canonical UUID of an own listing, or `imported-{id}` for an imported one. Stable across syncs — suitable input for deterministic consumer-side URL slugs.'],
    ['name' => 'x_source', 'type' => 'string', 'description' => '`own` (this node is the authority) or `imported` (a partner listing displayed under its usage terms).'],
    ['name' => 'x_provenance', 'type' => 'object', 'nullable' => true, 'description' => 'Where an imported listing came from; null for own listings.', 'children' => [
        ['name' => 'canonical', 'type' => 'string', 'description' => 'The listing\'s canonical URI at its authority node.'],
        ['name' => 'authority', 'type' => 'string', 'description' => 'Domain of the node that authored the listing.'],
        ['name' => 'received_at', 'type' => 'string', 'description' => 'When this node received the current copy (UTC, ISO 8601).'],
        ['name' => 'signature_verified', 'type' => 'boolean', 'description' => 'Whether the copy\'s distribution signature verified.'],
        ['name' => 'is_stale', 'type' => 'boolean', 'description' => 'True when the partner has not been reachable recently; the copy may lag the authority.'],
    ]],
    ['name' => 'x_attribution_text', 'type' => 'string', 'nullable' => true, 'description' => 'Attribution line the listing\'s usage terms require to be displayed alongside it; null when attribution is not required (and always null for own listings).'],
    ['name' => 'x_local_media', 'type' => 'array of objects', 'nullable' => true, 'description' => 'This node\'s derived image renditions for an imported listing (WebP, multiple widths); null for own listings, whose media URLs are in the document\'s `media` block.', 'children' => [
        ['name' => 'kind', 'type' => 'string', 'description' => '`profile`, `gallery` or `layout`.'],
        ['name' => 'caption', 'type' => 'string', 'nullable' => true, 'description' => 'Caption carried from the wire document.'],
        ['name' => 'sort', 'type' => 'integer', 'description' => 'Display order.'],
        ['name' => 'srcset', 'type' => 'object', 'description' => 'Rendition URLs keyed by pixel width (e.g. `480`, `960`, `1920`).'],
        ['name' => 'hero_srcset', 'type' => 'object', 'nullable' => true, 'description' => 'Cropped hero-variant URLs keyed by width; only on the profile image.'],
    ]],
];

return [

    'guides' => [

        'yachts' => [
            'intro' => 'yachts-intro.md',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'uri' => '/api/v1/yachts',
                    'action' => [YachtsController::class, 'index'],
                    'scope' => 'yachts:read',
                    'responses' => [
                        'Envelope' => ['fields' => [
                            ['name' => 'data', 'type' => 'array of objects', 'description' => 'Full listing documents in the protocol wire schema (see the intro), each carrying the x_ extension fields below.'],
                            ['name' => 'links', 'type' => 'object', 'description' => 'Contains `next`: the absolute URL of the next page, or null on the last page. Follow it to sweep the whole feed.'],
                            ['name' => 'meta', 'type' => 'object', 'description' => 'Pagination facts: `current_page`, `last_page`, `per_page`, `total`.'],
                        ]],
                        'Extension fields on every `data[]` item' => ['fields' => $extensionFields],
                    ],
                ],
                [
                    'method' => 'GET',
                    'uri' => '/api/v1/yachts/{key}',
                    'action' => [YachtsController::class, 'show'],
                    'scope' => 'yachts:read',
                    'responses' => [
                        'Envelope' => ['fields' => [
                            ['name' => 'data', 'type' => 'object', 'description' => 'One full listing document, in the same shape as a list item (including the x_ extension fields).'],
                        ]],
                    ],
                ],
            ],
        ],

    ],

];
