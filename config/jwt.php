<?php

return [
    'secret' => env('JWT_SECRET'),

    'algorithm' => 'HS256',

    /*
     * Checked on every decode. Without them a token minted by another service
     * sharing the secret would be accepted here.
     */
    'issuer' => env('JWT_ISSUER', 'helpdesk-api'),
    'audience' => env('JWT_AUDIENCE', 'helpdesk-web'),

    /*
     * Access tokens are not stored, so this window is how long a revoked
     * session can still act if token_version were not also checked.
     */
    'ttl_minutes' => (int) env('JWT_TTL', 10),

    'refresh' => [
        'ttl_days' => (int) env('REFRESH_TTL_DAYS', 30),
        'cookie' => env('REFRESH_COOKIE', 'refresh_token'),
        /*
         * Scoped to the auth routes, so the cookie is never attached to ticket
         * or user requests and cannot be leaked by an unrelated handler.
         */
        'path' => '/api/auth',
    ],
];
