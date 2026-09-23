<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // A credentialed request (cookies) cannot use a wildcard origin per the
    // Fetch spec, so this must be an explicit list. The SPA runs on its own
    // origin (Vite dev server / a static host in prod), never the API's own.
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Idempotency-Replayed', 'Retry-After'],

    'max_age' => 0,

    // Required for the Sanctum SPA cookie flow (see DECISIONS.md "Auth").
    'supports_credentials' => true,

];
