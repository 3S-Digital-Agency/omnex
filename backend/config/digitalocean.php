<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DigitalOcean (behind ServerProviderInterface)
    |--------------------------------------------------------------------------
    |
    | The provider is unconfigured until DO_API_TOKEN is set. Defaults are
    | applied by the provider when a request omits region/plan/image.
    |
    */

    'token' => env('DO_API_TOKEN'),

    'default_region' => env('DO_DEFAULT_REGION', 'nyc1'),

    'default_size' => env('DO_DEFAULT_SIZE', 's-1vcpu-1gb'),

    'default_image' => env('DO_DEFAULT_IMAGE', 'ubuntu-24-04-x64'),

    'ssh_key_id' => env('DO_SSH_KEY_ID'),

];
