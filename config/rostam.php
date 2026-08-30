<?php

// SPDX-License-Identifier: Apache-2.0
return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The connection used when none is named - by the Rostam facade, and by any
    | cache store that does not set its own "connection" key.
    |
    */

    'default' => env('ROSTAM_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Rostam's key-value engine is only reachable over its binary TCP protocol -
    | it has no REST surface - so "port" here is the server's -tcp listener, not
    | its HTTP one. Start the server with, for example:
    |
    |     ROSTAM_API_KEY=secret rostam-server -tcp 127.0.0.1:7000 -data /var/lib/rostam
    |
    */

    'connections' => [

        'default' => [
            'host' => env('ROSTAM_HOST', '127.0.0.1'),
            'port' => env('ROSTAM_PORT', 7000),

            // Matches the server's -api-key / ROSTAM_API_KEY. Empty means the
            // server is running open (-insecure).
            'token' => env('ROSTAM_TOKEN', ''),

            // Seconds. connect_timeout covers the dial, timeout covers each
            // read and write once connected.
            'connect_timeout' => env('ROSTAM_CONNECT_TIMEOUT', 2.0),
            'timeout' => env('ROSTAM_TIMEOUT', 5.0),

            // How many idle sockets to keep. Batch operations pipeline over a
            // single socket, so this only needs to grow for concurrent use of
            // the same client instance.
            'pool_size' => env('ROSTAM_POOL_SIZE', 4),

            // PHP-level persistent sockets, kept alive across requests by the
            // worker process. Leave off unless you have measured a win.
            'persistent' => env('ROSTAM_PERSISTENT', false),

            // Re-send an idempotent op once when a pooled socket turns out to
            // have been closed while idle.
            'retry_on_stale_connection' => true,

            'tls' => [
                'enabled' => env('ROSTAM_TLS', false),
                'verify_peer' => env('ROSTAM_TLS_VERIFY', true),
                'ca' => env('ROSTAM_TLS_CA'),
                'cert' => env('ROSTAM_TLS_CERT'),
                'key' => env('ROSTAM_TLS_KEY'),
            ],
        ],

    ],

];
