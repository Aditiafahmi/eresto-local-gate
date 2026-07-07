<?php

namespace Tests\Feature;

use Tests\TestCase;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;

class HikvisionSyncCustomerTest extends TestCase
{
    public function test_sync_customer_calls_hikvision_endpoints_with_mock_client(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $response = $this->postJson('/api/sync-customer', [
            'member_id' => 'TEST-MOCK-001',
            'name' => 'Mock Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'card_no' => 'CARD-MOCK-001',
            'face_image_base64' => 'base64-image',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.xgym_entrance.status', 'success')
            ->assertJsonPath('data.xgym_entrance.face_synced', true)
            ->assertJsonPath('data.xgym_entrance.face_image_count', 1);

        $this->assertCount(3, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/1/picture', $httpClient->posts[2]['uri']);

        $this->assertSame('TEST-MOCK-001', $httpClient->posts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Mock Customer', $httpClient->posts[0]['data']['UserInfo']['name']);
        $this->assertSame('CARD-MOCK-001', $httpClient->posts[1]['data']['CardInfo']['cardNo']);
        $this->assertSame('TEST-MOCK-001', $httpClient->posts[2]['data']['faceInfo']['employeeNo']);
        $this->assertSame('base64-image', $httpClient->posts[2]['data']['faceData']);
    }

    public function test_sync_customer_defaults_card_no_to_member_id(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $response = $this->postJson('/api/sync-customer', [
            'member_id' => 'M-1261-ABCD',
            'name' => 'Customer Test',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.xgym_entrance.status', 'success')
            ->assertJsonPath('data.xgym_entrance.face_synced', false)
            ->assertJsonPath('data.xgym_entrance.face_image_count', 0);

        $this->assertCount(2, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertSame('M-1261-ABCD', $httpClient->posts[1]['data']['CardInfo']['cardNo']);
    }

    public function test_sync_customer_calls_face_endpoint_for_each_face_sample(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $response = $this->postJson('/api/sync-customer', [
            'member_id' => 'M-1262-WXYZ',
            'name' => 'Multiple Face Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'face_images_base64' => [
                'base64-front',
                'base64-left',
                'base64-right',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.xgym_entrance.status', 'success')
            ->assertJsonPath('data.xgym_entrance.face_synced', true)
            ->assertJsonPath('data.xgym_entrance.face_image_count', 3);

        $this->assertCount(5, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/1/picture', $httpClient->posts[2]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/1/picture', $httpClient->posts[3]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/1/picture', $httpClient->posts[4]['uri']);

        $this->assertSame('base64-front', $httpClient->posts[2]['data']['faceData']);
        $this->assertSame('base64-left', $httpClient->posts[3]['data']['faceData']);
        $this->assertSame('base64-right', $httpClient->posts[4]['data']['faceData']);
    }

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

        $httpClient = new RecordingHikvisionHttpClient();
        $app = app();

        $app->instance(HttpClientInterface::class, $httpClient);
        $app->forgetInstance(DeviceManager::class);
        $app->forgetInstance(HikvisionClient::class);
        Hikvision::clearResolvedInstance(DeviceManager::class);

        return $httpClient;
    }
}

class RecordingHikvisionHttpClient implements HttpClientInterface
{
    public array $posts = [];

    public function get(string $uri, array $options = []): array
    {
        return ['status' => 'ok'];
    }

    public function post(string $uri, array $data = [], array $options = []): array
    {
        $this->posts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

        return ['status' => 'ok'];
    }

    public function put(string $uri, array $data = [], array $options = []): array
    {
        return ['status' => 'ok'];
    }

    public function delete(string $uri, array $options = []): array
    {
        return ['status' => 'ok'];
    }

    public function postMultipart(string $uri, array $multipart = [], array $options = []): array
    {
        return ['status' => 'ok'];
    }
}
