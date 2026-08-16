<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant isolation (defense-in-depth)
    |--------------------------------------------------------------------------
    |
    | The application-level global scope (docs/security.md) is always enforced.
    | PostgreSQL Row-Level Security is the second, DB-enforced layer. It stays
    | OFF until the RLS test suite passes against a real PostgreSQL instance,
    | because a misconfigured policy would lock legitimate tenants out.
    |
    */

    'enforce_rls' => env('OMNEX_ENFORCE_RLS', false),

    'tenant' => [
        'header' => 'X-Organization',
    ],

    'mfa' => [
        'issuer' => env('APP_NAME', 'OMNEX'),
        'recovery_codes_count' => 8,
        'verification_window' => 1,
        'challenge_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Locales a user may select in their account settings.
    |
    */

    'locales' => ['en', 'fr'],

    /*
    |--------------------------------------------------------------------------
    | Domain + DNS engines
    |--------------------------------------------------------------------------
    |
    | Provider names resolve through DomainProviderRegistry / DnsProviderRegistry.
    | The sandbox is deterministic and safe for local/test environments; a real
    | registrar/DNS provider is wired later without touching the engine code.
    |
    */

    'domain' => [
        'provider' => env('OMNEX_DOMAIN_PROVIDER', 'sandbox'),
        'dns_provider' => env('OMNEX_DNS_PROVIDER', 'sandbox'),
        'default_registration_years' => (int) env('OMNEX_DOMAIN_REGISTRATION_YEARS', 1),
        'default_nameservers' => [
            'ns1.omnex.io',
            'ns2.omnex.io',
        ],
        'expiration_warning_days' => (int) env('OMNEX_DOMAIN_EXPIRATION_WARNING_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | OMNEX Drive (object storage)
    |--------------------------------------------------------------------------
    |
    | Provider names resolve through StorageProviderRegistry. The sandbox is
    | an in-memory store for local/test environments; the S3-compatible
    | provider activates only once its credentials are set.
    |
    */

    'storage' => [
        'provider' => env('OMNEX_STORAGE_PROVIDER', 'sandbox'),
        'default_quota_bytes' => (int) env('OMNEX_STORAGE_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),
        'signed_url_ttl' => (int) env('OMNEX_STORAGE_SIGNED_URL_TTL', 300),
        's3' => [
            'endpoint' => env('OMNEX_STORAGE_S3_ENDPOINT', ''),
            'region' => env('OMNEX_STORAGE_S3_REGION', 'us-east-1'),
            'bucket' => env('OMNEX_STORAGE_S3_BUCKET', ''),
            'key' => env('OMNEX_STORAGE_S3_KEY', ''),
            'secret' => env('OMNEX_STORAGE_S3_SECRET', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OMNEX Sites
    |--------------------------------------------------------------------------
    |
    | Provider names resolve through SiteProviderRegistry. The sandbox is a
    | deterministic in-memory platform; the custom provider activates only
    | once its endpoint is set (config/customsites.php).
    |
    */

    'sites' => [
        'provider' => env('OMNEX_SITE_PROVIDER', 'sandbox'),
        'default_branch' => env('OMNEX_SITE_DEFAULT_BRANCH', 'main'),
        'frameworks' => ['static', 'laravel', 'next'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Server-Sent Events settings for the real-time notification stream.
    | `sse_max_seconds` caps a single stream connection (clients reconnect);
    | `sse_heartbeat_seconds` is the comment-frame keep-alive interval.
    |
    */

    'notifications' => [
        'sse_max_seconds' => (int) env('OMNEX_NOTIFICATIONS_SSE_MAX_SECONDS', 60),
        'sse_heartbeat_seconds' => (int) env('OMNEX_NOTIFICATIONS_SSE_HEARTBEAT_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity stream
    |--------------------------------------------------------------------------
    |
    | Server-Sent Events settings for the real-time activity feed. Mirrors the
    | notification stream: `sse_max_seconds` caps a connection, `sse_heartbeat_seconds`
    | is the comment-frame keep-alive interval.
    |
    */

    'activity' => [
        'sse_max_seconds' => (int) env('OMNEX_ACTIVITY_SSE_MAX_SECONDS', 60),
        'sse_heartbeat_seconds' => (int) env('OMNEX_ACTIVITY_SSE_HEARTBEAT_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time streams (SSE transport)
    |--------------------------------------------------------------------------
    |
    | Backing store for the notification and activity streams.
    |
    |   inprocess — in-memory, single process. Safe default for local dev and
    |               the test suite.
    |   redis     — Redis pub/sub, so events published by one PHP worker reach
    |               subscribers on another (required for horizontal scaling).
    |
    | `prefix` namespaces the pub/sub channels; `redis_connection` selects the
    | connection defined in the `database.redis` config.
    |
    */

    'streams' => [
        'driver' => env('OMNEX_STREAM_DRIVER', 'inprocess'),
        'prefix' => env('OMNEX_STREAM_PREFIX', 'omnex:'),
        'redis_connection' => env('OMNEX_STREAM_REDIS_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Provider names resolve through PaymentProviderRegistry. The sandbox is
    | deterministic and safe for local/test environments; the Stripe provider
    | activates only once its keys are set.
    |
    */

    'billing' => [
        'provider' => env('OMNEX_BILLING_PROVIDER', 'sandbox'),
        'currency' => env('OMNEX_BILLING_CURRENCY', 'usd'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        'stripe' => [
            'secret' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Center
    |--------------------------------------------------------------------------
    |
    | Severity penalties subtracted from the 100-point Security Score for
    | every open finding.
    |
    */

    'security' => [
        'severity_penalties' => [
            'high' => 25,
            'medium' => 15,
            'low' => 10,
        ],
    ],

];
