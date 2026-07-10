<?php

namespace Tests\Support;

use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;

trait FakesHikvisionHttpClient
{
    private function fakeHikvisionHttpClient(array $deviceNames = ['xgym_entrance']): RecordingHikvisionHttpClient
    {
        $devices = [];

        foreach (array_values($deviceNames) as $index => $deviceName) {
            $devices[$deviceName] = [
                'ip' => '10.0.0.'.(10 + $index),
                'port' => 80,
                'username' => 'admin',
                'password' => 'secret',
                'protocol' => 'http',
                'timeout' => 30,
                'verify_ssl' => false,
            ];
        }

        config([
            'hikvision.default' => $deviceNames[0] ?? null,
            'hikvision.devices' => $devices,
        ]);

        $httpClient = new RecordingHikvisionHttpClient;
        $app = app();

        $app->instance(HttpClientInterface::class, $httpClient);
        $app->forgetInstance(DeviceManager::class);
        $app->forgetInstance(HikvisionClient::class);
        Hikvision::clearResolvedInstance(DeviceManager::class);

        return $httpClient;
    }
}
