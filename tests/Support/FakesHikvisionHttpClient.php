<?php

namespace Tests\Support;

use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;

trait FakesHikvisionHttpClient
{
    private function fakeHikvisionHttpClient(): RecordingHikvisionHttpClient
    {
        config([
            'hikvision.default' => 'xgym_entrance',
            'hikvision.devices' => [
                'xgym_entrance' => [
                    'ip' => '10.0.0.10',
                    'port' => 80,
                    'username' => 'admin',
                    'password' => 'secret',
                    'protocol' => 'http',
                    'timeout' => 30,
                    'verify_ssl' => false,
                ],
            ],
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
