<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The data API (routes/api.php) is consumed from browsers — e.g. a
    | static site's search island calling it with a publishable key — so
    | it answers cross-origin requests. Open origins are safe here: every
    | request still needs an API key, and browser keys are locked to
    | their configured domains by AuthenticateApiKey (Origin/Referer
    | check), which is the actual access control. The federation API is
    | node-to-node and stays outside these paths.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
