<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identity Domain
    |--------------------------------------------------------------------------
    |
    | The node's identity is its domain (federation-protocol.md §Identity and
    | Trust Model). It carries the well-known trust root, the API base path,
    | the host line of every request signature, and every canonical listing
    | URI — choose it once and deliberately; the choice is permanent in
    | practice. Federation routes are refused (404) on any other hostname so
    | the node's identity cannot fork (identity-domain-hosting.md).
    |
    */

    'domain' => env('OPENYACHT_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Node Identity
    |--------------------------------------------------------------------------
    |
    | The node UUID is generated once at installation (php artisan
    | openyacht:install) and published in the well-known document. It is not
    | a trust anchor; it exists so partners can detect that a domain now
    | hosts a different installation (federation-protocol.md §Identity).
    |
    */

    'node_uuid' => env('OPENYACHT_NODE_UUID'),

    'node_name' => env('OPENYACHT_NODE_NAME', env('APP_NAME', 'OpenYacht Node')),

    'website' => env('OPENYACHT_WEBSITE'),

    'software' => 'openyacht-reference/0.1',

    /*
    |--------------------------------------------------------------------------
    | Protocol
    |--------------------------------------------------------------------------
    */

    'protocol_versions' => ['1.0'],

    // Replay-protection window in seconds (federation-protocol.md §Request
    // Signing: reject timestamps outside ±300 seconds of server time).
    'timestamp_tolerance_seconds' => 300,

    'limits' => [
        'page_size_max' => 100,
        'rate_per_hour' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Imported Media
    |--------------------------------------------------------------------------
    |
    | The wire carries one large image per media item (listing-schema.md
    | §Media); importing nodes generate their own derived sizes. Renditions
    | are WebP at the widths below (for srcset), plus a cropped hero
    | variant of the profile image. The disk is swappable for S3/R2 in
    | production via the standard Laravel storage configuration.
    |
    */

    'media' => [
        'disk' => env('OPENYACHT_MEDIA_DISK', 'public'),
        'widths' => [480, 960, 1920],
        'profile_crop_ratio' => 16 / 9,
        'quality' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Location Map
    |--------------------------------------------------------------------------
    |
    | The data-entry map used to plot and pick listing coordinates. Purely a
    | UI aid — nothing on the wire depends on it. OpenStreetMap needs no
    | account or key; setting the provider to "mapbox" upgrades tiles and
    | geocoding but requires an access token (without one the app falls
    | back to OpenStreetMap).
    |
    */

    'map' => [
        'provider' => env('OPENYACHT_MAP_PROVIDER', 'openstreetmap'),
        'mapbox_token' => env('OPENYACHT_MAPBOX_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Terms
    |--------------------------------------------------------------------------
    |
    | The terms attached to every distributed listing (yacht-identity.md
    | §Sharing Permissions and Usage Terms). expires_with_listing true is
    | the default and RECOMMENDED value.
    |
    */

    'usage' => [
        'display' => true,
        'attribution_required' => true,
        'attribution_text' => env('OPENYACHT_ATTRIBUTION_TEXT'),
        'marketing_materials' => true,
        'ai_indexing' => true,
        'expires_with_listing' => true,
    ],

    // Terminal listings stay dereferenceable at their canonical URI for
    // this long before 410 Gone (yacht-identity.md §Lifecycle).
    'terminal_retention_months' => 12,

];
