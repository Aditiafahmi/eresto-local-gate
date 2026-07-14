<?php

return [
    'cloud' => [
        'enabled' => env('ERESTO_MOCK_CLOUD_ENABLED', false),

        'customers' => [
            'M123' => [
                'member_id' => 'M123',
                'name' => 'Mock Active Customer',
                'start_date' => '2026-01-01T00:00:00+07:00',
                'end_date' => '2026-12-31T23:59:59+07:00',
                'status' => 'active',
                'card_no' => 'CARD-M123',
                'face_images_base64' => [
                    base64_encode('mock-face-image-M123'),
                ],
            ],
            'M124' => [
                'member_id' => 'M124',
                'name' => 'Mock Inactive Customer',
                'start_date' => '2026-01-01T00:00:00+07:00',
                'end_date' => '2026-12-31T23:59:59+07:00',
                'status' => 'inactive',
                'card_no' => null,
                'face_images_base64' => [],
            ],
        ],
    ],

    'hikvision' => [
        'server_enabled' => env('ERESTO_MOCK_HIKVISION_SERVER_ENABLED', false),
        'device_name' => env('ERESTO_MOCK_HIKVISION_DEVICE_NAME', 'mock-device'),
        'storage_path' => env(
            'ERESTO_MOCK_HIKVISION_STORAGE_PATH',
            storage_path('app/mock-hikvision')
        ),
    ],
];
