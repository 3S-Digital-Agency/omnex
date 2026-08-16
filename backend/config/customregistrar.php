<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom registrar (behind DomainProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Points at any HTTP/JSON registrar gateway. The provider POSTs
    | {"command": ..., ...} and expects the interface's response shapes under
    | a `data` key. Set NEXUS_DOMAIN_PROVIDER=custom to activate it.
    |
    */

    'endpoint' => env('CUSTOM_REGISTRAR_ENDPOINT'),
    'api_key' => env('CUSTOM_REGISTRAR_API_KEY'),
];
