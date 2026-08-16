<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom cloud gateway (behind ServerProviderInterface)
    |--------------------------------------------------------------------------
    |
    | Points at any HTTP/JSON compute gateway. The provider POSTs
    | {"command": ..., ...} and expects the interface's response shapes under
    | a `data` key. Set OMNEX_CLOUD_PROVIDER=custom to activate it.
    |
    */

    'endpoint' => env('CUSTOM_CLOUD_ENDPOINT'),
    'api_key' => env('CUSTOM_CLOUD_API_KEY'),

];
