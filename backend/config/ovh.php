<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OVHcloud registrar (behind DomainProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Activates only when NEXUS_DOMAIN_PROVIDER=ovh AND the three credentials
    | below are present. Credentials are created at
    | https://eu.api.ovh.com/createToken/ (or the regional console). The
    | subsidiary drives pricing/eligibility in the order cart (FR, GB, CA, …).
    |
    */

    'endpoint' => env('OVH_ENDPOINT', 'https://eu.api.ovh.com/1.0'),
    'application_key' => env('OVH_APPLICATION_KEY'),
    'application_secret' => env('OVH_APPLICATION_SECRET'),
    'consumer_key' => env('OVH_CONSUMER_KEY'),
    'subsidiary' => env('OVH_SUBSIDIARY', 'FR'),
];
