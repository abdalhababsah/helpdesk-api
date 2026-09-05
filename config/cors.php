<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
     * A single named origin, never a wildcard. A wildcard cannot be combined
     * with credentials, and the frontend has to send the refresh cookie.
     */
    'allowed_origins' => array_filter([env('WEB_ORIGIN')]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    /*
     * Required. Without it the browser discards the response to any credentialed
     * cross-origin request, so refresh silently never works and people are
     * signed out whenever their access token expires.
     */
    'supports_credentials' => true,
];
