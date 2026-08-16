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

    /*
    |--------------------------------------------------------------------------
    | Default registrant contact
    |--------------------------------------------------------------------------
    |
    | Namecheap requires full WHOIS contacts for registrations, transfers and
    | contact updates. These defaults are used unless the request supplies a
    | `contacts` array (keys: first_name, last_name, address1, address2, city,
    | state_province, postal_code, country, phone, email_address).
    |
    */

    'registrant' => [
        'first_name' => env('NAMECHEAP_FIRST_NAME'),
        'last_name' => env('NAMECHEAP_LAST_NAME'),
        'address1' => env('NAMECHEAP_ADDRESS1'),
        'address2' => env('NAMECHEAP_ADDRESS2'),
        'city' => env('NAMECHEAP_CITY'),
        'state_province' => env('NAMECHEAP_STATE_PROVINCE'),
        'postal_code' => env('NAMECHEAP_POSTAL_CODE'),
        'country' => env('NAMECHEAP_COUNTRY'),
        'phone' => env('NAMECHEAP_PHONE'),
        'email_address' => env('NAMECHEAP_EMAIL_ADDRESS'),
    ],
];
