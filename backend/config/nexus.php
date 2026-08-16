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

    'enforce_rls' => env('NEXUS_ENFORCE_RLS', false),

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
        'provider' => env('NEXUS_DOMAIN_PROVIDER', 'sandbox'),
        'dns_provider' => env('NEXUS_DNS_PROVIDER', 'sandbox'),
        'default_registration_years' => (int) env('NEXUS_DOMAIN_REGISTRATION_YEARS', 1),
        'default_nameservers' => [
            'ns1.omnex.io',
            'ns2.omnex.io',
        ],
        'expiration_warning_days' => (int) env('NEXUS_DOMAIN_EXPIRATION_WARNING_DAYS', 30),
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
        'provider' => env('NEXUS_STORAGE_PROVIDER', 'sandbox'),
        'default_quota_bytes' => (int) env('NEXUS_STORAGE_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),
        'signed_url_ttl' => (int) env('NEXUS_STORAGE_SIGNED_URL_TTL', 300),
        's3' => [
            'endpoint' => env('NEXUS_STORAGE_S3_ENDPOINT', ''),
            'region' => env('NEXUS_STORAGE_S3_REGION', 'us-east-1'),
            'bucket' => env('NEXUS_STORAGE_S3_BUCKET', ''),
            'key' => env('NEXUS_STORAGE_S3_KEY', ''),
            'secret' => env('NEXUS_STORAGE_S3_SECRET', ''),
        ],
    ],

];
