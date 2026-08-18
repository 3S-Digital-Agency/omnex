<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Let's Encrypt (behind SslProviderInterface)
    |--------------------------------------------------------------------------
    |
    | TLS issuance via the ACME v2 protocol (RFC 8555) using the dns-01
    | challenge: OMNEX places a temporary `_acme-challenge` TXT record through
    | the tenant's active DnsProviderInterface, proves control of the domain,
    | then removes it. No external HTTP server or port forwarding is required,
    | and wildcard certificates work.
    |
    | The provider activates only when OMNEX_SSL_PROVIDER=letsencrypt AND an
    | account contact e-mail is set. The ACME account private key is generated
    | once and persisted (so renewals reuse the same account); it lives under
    | `storage/keys` which is gitignored.
    |
    | `directory` defaults to Let's Encrypt STAGING — use it to validate the
    | full flow without hitting production rate limits, then switch to the
    | live directory for real certificates.
    |
    */

    'directory' => env('OMNEX_LETSENCRYPT_DIRECTORY', 'https://acme-staging-v02.api.letsencrypt.org/directory'),

    // Live directory: https://acme-v02.api.letsencrypt.org/directory

    // Contact e-mail for the ACME account (also receives expiry notifications).
    'email' => env('OMNEX_LETSENCRYPT_EMAIL'),

    // Where the ACME account private key (RSA) is persisted.
    'account_key_path' => env('OMNEX_LETSENCRYPT_ACCOUNT_KEY_PATH', storage_path('keys/acme/account.pem')),

    // Certificate (CSR) private key size.
    'certificate_key_bits' => (int) env('OMNEX_LETSENCRYPT_CERT_KEY_BITS', 2048),

    // dns-01 authorization polling: interval between checks and max attempts
    // before giving up (DNS propagation can take a little while).
    'poll_interval_ms' => (int) env('OMNEX_LETSENCRYPT_POLL_INTERVAL_MS', 3000),
    'poll_attempts' => (int) env('OMNEX_LETSENCRYPT_POLL_ATTEMPTS', 10),
];
