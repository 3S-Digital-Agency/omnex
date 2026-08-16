<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Namecheap registrar (behind DomainProviderInterface)
    |--------------------------------------------------------------------------
    |
    | The provider activates only when NEXUS_DOMAIN_PROVIDER=namecheap AND the
    | three credentials below are present. Keep the sandbox as the default
    | (NEXUS_DOMAIN_PROVIDER=sandbox) — no real domain is registered there.
    |
    | ClientIp must be a public IP whitelisted in the Namecheap API settings
    | (production). For the sandbox endpoint (api.sandbox.namecheap.com) any
    | IP is accepted.
    |
    */

    'endpoint' => env('NAMECHEAP_ENDPOINT', 'https://api.namecheap.com/xml.response'),
    'api_user' => env('NAMECHEAP_API_USER'),
    'api_key' => env('NAMECHEAP_API_KEY'),
    'username' => env('NAMECHEAP_USERNAME'),
    'client_ip' => env('NAMECHEAP_CLIENT_IP', '127.0.0.1'),
];
