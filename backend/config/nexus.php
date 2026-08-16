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

];
