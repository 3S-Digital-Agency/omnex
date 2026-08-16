<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hetzner Cloud (behind ServerProviderInterface)
    |--------------------------------------------------------------------------
    |
    | The provider is unconfigured until HETZNER_API_TOKEN is set. Defaults
    | are applied by the provider when a request omits region/plan/image.
    |
    */

    'token' => env('HETZNER_API_TOKEN'),

    'default_location' => env('HETZNER_DEFAULT_LOCATION', 'fsn1'),

    'default_server_type' => env('HETZNER_DEFAULT_SERVER_TYPE', 'cpx11'),

    'default_image' => env('HETZNER_DEFAULT_IMAGE', 'ubuntu-24.04'),

    'ssh_key_id' => env('HETZNER_SSH_KEY_ID'),

];
