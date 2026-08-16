<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social login (GAFAM + sandbox)
    |--------------------------------------------------------------------------
    |
    | The sandbox provider always runs (deterministic) so the full OAuth flow —
    | redirect → callback → account link/creation — is testable with zero
    | external credentials. A real provider becomes active only once its keys
    | are present in .env (see isConfigured() on each adapter).
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // How long a one-time completion code stays valid (seconds).
    'completion_ttl' => (int) env('SOCIAL_COMPLETION_TTL', 300),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'amazon' => [
        'client_id' => env('AMAZON_CLIENT_ID'),
        'client_secret' => env('AMAZON_CLIENT_SECRET'),
        'redirect' => env('AMAZON_REDIRECT_URI'),
    ],

    // Serveurs du Peuple — Nextcloud OAuth2 (Path A). The id is the Nextcloud
    // username; email is not asserted verified by this provider.
    'sdp' => [
        'base_url' => env('SDP_BASE_URL', 'https://cloud.serveursdupeuple.net'),
        'client_id' => env('SDP_CLIENT_ID'),
        'client_secret' => env('SDP_CLIENT_SECRET'),
        'redirect' => env('SDP_REDIRECT_URI'),
    ],
];
