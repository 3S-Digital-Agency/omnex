<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare (behind DnsProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Managed DNS via the Cloudflare v4 API: zones, DNS records (with proxy /
    | CDN on/off per record) and DNSSEC (DS records). The provider activates
    | only when OMNEX_DNS_PROVIDER=cloudflare AND a token is set; without it
    | every call throws. Keep the sandbox as the default (sandbox) — no real
    | zone is touched there.
    |
    | Create an API token with the "Zone / Zone / Edit" and
    | "Zone / DNS / Edit" permissions (https://dash.cloudflare.com/profile/api-tokens).
    |
    */

    'endpoint' => env('CLOUDFLARE_ENDPOINT', 'https://api.cloudflare.com/client/v4'),
    'api_token' => env('CLOUDFLARE_API_TOKEN'),
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),

    // Whether new zones are created proxied through Cloudflare's CDN by
    // default (record-level `proxied` per record still applies).
    'default_proxied' => (bool) env('CLOUDFLARE_DEFAULT_PROXIED', false),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Pages (behind SiteProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Static hosting via Cloudflare Pages: projects, deployments (build
    | triggers) and retry-as-rollback. The provider activates only when
    | OMNEX_SITE_PROVIDER=cloudflare AND a token + account id are set; without
    | them every call throws. The same API token can carry both DNS and Pages
    | permissions ("Account / Cloudflare Pages / Edit" + "Zone / DNS / Edit").
    |
    */

    'sites' => [
        // Default production branch used when a new Pages project is created.
        'production_branch' => env('CLOUDFLARE_PAGES_PRODUCTION_BRANCH', 'main'),
    ],
];
