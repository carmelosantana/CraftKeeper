<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | CraftKeeper's realtime transport is Laravel Reverb (self-hosted,
    | supervised alongside the app — see docker/supervisor/supervisord.conf).
    | Local development and the test suite override BROADCAST_CONNECTION to
    | "log"/"null" respectively so neither needs a running Reverb server.
    |
    | The FALLBACK here is "log", not "reverb", and that matters: realtime
    | streaming is optional in CraftKeeper, but routes/channels.php calls
    | Broadcast::channel() during application boot. With a "reverb" fallback
    | and no REVERB_* credentials present, the driver constructs Pusher with
    | a null auth key and throws before the framework finishes booting — so
    | the app fails to start at all, rather than merely losing streaming.
    | That took down `composer install` itself (its post-autoload-dump hook
    | runs `artisan package:discover`) in any environment without a .env,
    | including CI and a plain `docker run` of the published image.
    |
    | Reverb is therefore opt-in: set BROADCAST_CONNECTION=reverb together
    | with the REVERB_* credentials. Anything that wants realtime must say
    | so explicitly — see docker-compose.integration.yml.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
