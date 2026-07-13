<?php

$useMockDevices = env('APP_ENV', 'production') !== 'production'
    && (bool) env('HIKVISION_USE_MOCK_DEVICES', false);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Hikvision Device Configuration
    |--------------------------------------------------------------------------
    |
    | Specify which device should be used by default when not explicitly
    | specified. This should match one of the device keys below.
    |
    */
    'default' => env('HIKVISION_DEFAULT_DEVICE', 'xgym_entrance'),

    'mock_devices_enabled' => $useMockDevices,

    /*
    |--------------------------------------------------------------------------
    | Hikvision Devices
    |--------------------------------------------------------------------------
    |
    | Configure Hikvision devices here. XGym currently has two gates:
    | entrance and exit. Each device has its own IP, while credentials can
    | either be shared globally or overridden per device.
    |
    | You can add more devices later if needed.
    |
    */
    'devices' => [
        'xgym_entrance' => [
            'ip' => env(
                'HIKVISION_XGYM_ENTRANCE_IP',
                $useMockDevices ? 'mock-hikvision-entrance' : '192.168.1.101'
            ),
            'port' => env(
                'HIKVISION_XGYM_ENTRANCE_PORT',
                $useMockDevices ? 8080 : env('HIKVISION_PORT', 80)
            ),
            'username' => env('HIKVISION_XGYM_ENTRANCE_USERNAME', env('HIKVISION_USERNAME', 'admin')),
            'password' => env(
                'HIKVISION_XGYM_ENTRANCE_PASSWORD',
                $useMockDevices ? 'mock-secret' : env('HIKVISION_PASSWORD')
            ),
            'protocol' => env('HIKVISION_XGYM_ENTRANCE_PROTOCOL', env('HIKVISION_PROTOCOL', 'http')),
            'timeout' => env('HIKVISION_XGYM_ENTRANCE_TIMEOUT', env('HIKVISION_TIMEOUT', 30)),
            'verify_ssl' => env('HIKVISION_XGYM_ENTRANCE_VERIFY_SSL', env('HIKVISION_VERIFY_SSL', false)),
        ],

        'xgym_exit' => [
            'ip' => env(
                'HIKVISION_XGYM_EXIT_IP',
                $useMockDevices ? 'mock-hikvision-exit' : '192.168.1.102'
            ),
            'port' => env(
                'HIKVISION_XGYM_EXIT_PORT',
                $useMockDevices ? 8080 : env('HIKVISION_PORT', 80)
            ),
            'username' => env('HIKVISION_XGYM_EXIT_USERNAME', env('HIKVISION_USERNAME', 'admin')),
            'password' => env(
                'HIKVISION_XGYM_EXIT_PASSWORD',
                $useMockDevices ? 'mock-secret' : env('HIKVISION_PASSWORD')
            ),
            'protocol' => env('HIKVISION_XGYM_EXIT_PROTOCOL', env('HIKVISION_PROTOCOL', 'http')),
            'timeout' => env('HIKVISION_XGYM_EXIT_TIMEOUT', env('HIKVISION_TIMEOUT', 30)),
            'verify_ssl' => env('HIKVISION_XGYM_EXIT_VERIFY_SSL', env('HIKVISION_VERIFY_SSL', false)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Format
    |--------------------------------------------------------------------------
    */
    'format' => env('HIKVISION_FORMAT', 'json'), // json or xml

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('HIKVISION_LOGGING', true),
        'channel' => env('HIKVISION_LOG_CHANNEL', 'stack'),
    ],

    'sync_status' => [
        'store' => env('HIKVISION_SYNC_STATUS_STORE', 'redis'),
        'ttl' => env('HIKVISION_SYNC_STATUS_TTL', 604800),
    ],
];
