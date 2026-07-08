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
            'face_images_base64' => ['base64-image'],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Sync job queued')
            ->assertJsonPath('member_id', 'TEST-MOCK-001');

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

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Sync job queued')
            ->assertJsonPath('member_id', 'M-1261-ABCD');

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

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Sync job queued')
            ->assertJsonPath('member_id', 'M-1262-WXYZ');

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

    public function test_update_customer_access_calls_hikvision_modify_endpoint(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $response = $this->patchJson('/api/sync-customer/M-1263-EDIT', [
            'name' => 'Updated Customer',
            'start_date' => '2026-08-01T00:00:00',
            'end_date' => '2027-08-01T23:59:59',
            'status' => 'inactive',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.xgym_entrance.status', 'success');

        $this->assertCount(1, $httpClient->puts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Modify', $httpClient->puts[0]['uri']);
        $this->assertSame('M-1263-EDIT', $httpClient->puts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Updated Customer', $httpClient->puts[0]['data']['UserInfo']['name']);
        $this->assertFalse($httpClient->puts[0]['data']['UserInfo']['Valid']['enable']);
        $this->assertSame('2026-08-01T00:00:00', $httpClient->puts[0]['data']['UserInfo']['Valid']['beginTime']);
        $this->assertSame('2027-08-01T23:59:59', $httpClient->puts[0]['data']['UserInfo']['Valid']['endTime']);
    }

    public function test_delete_customer_calls_hikvision_delete_endpoints(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $response = $this->deleteJson('/api/sync-customer/M-1264-DELETE');

        $response->assertOk()
            ->assertJsonPath('data.xgym_entrance.status', 'success');

        $this->assertCount(2, $httpClient->puts);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Delete', $httpClient->puts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Delete', $httpClient->puts[1]['uri']);
        $this->assertSame(
            'M-1264-DELETE',
            $httpClient->puts[0]['data']['CardInfoDelCond']['EmployeeNoList'][0]['employeeNo']
        );
        $this->assertSame(
            'M-1264-DELETE',
            $httpClient->puts[1]['data']['UserInfoDelCond']['EmployeeNoList'][0]['employeeNo']
        );
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
    public array $puts = [];

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
        $this->puts[] = [
            'uri' => $uri,
            'data' => $data,
            'options' => $options,
        ];

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
