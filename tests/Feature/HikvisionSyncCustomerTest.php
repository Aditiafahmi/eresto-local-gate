<?php

namespace Tests\Feature;

use App\DTOs\CloudCustomerData;
use App\Jobs\SyncCustomerToGateJob;
use App\Services\Cloud\CloudCustomerClient;
use App\Services\Hikvision\CustomerGatePayloadBuilder;
use App\Services\Hikvision\CustomerGateSyncDispatcher;
use App\Services\Hikvision\CustomerGateSyncService;
use App\Services\Hikvision\CustomerGateSyncStatusStore;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesHikvisionHttpClient;
use Tests\TestCase;
use Throwable;

class HikvisionSyncCustomerTest extends TestCase
{
    use FakesHikvisionHttpClient;

    public function test_sync_customer_calls_hikvision_endpoints_with_mock_client(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $this->dispatchCustomer([
            'member_id' => 'TEST-MOCK-001',
            'name' => 'Mock Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'card_no' => 'CARD-MOCK-001',
            'face_images_base64' => ['base64-image'],
        ]);

        $this->assertCount(2, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertCount(1, $httpClient->postMultiparts);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FaceDataRecord', $httpClient->postMultiparts[0]['uri']);

        $this->assertSame('TEST-MOCK-001', $httpClient->posts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Mock Customer', $httpClient->posts[0]['data']['UserInfo']['name']);
        $this->assertSame('CARD-MOCK-001', $httpClient->posts[1]['data']['CardInfo']['cardNo']);

        $faceRecord = json_decode($httpClient->postMultiparts[0]['multipart'][0]['contents'], true);
        $this->assertSame('TEST-MOCK-001', $faceRecord['FPID']);
        $this->assertSame('1', $faceRecord['FDID']);
        $this->assertSame('base64-image', $httpClient->postMultiparts[0]['multipart'][1]['contents']);
    }

    public function test_sync_customer_defaults_card_no_to_member_id(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $this->dispatchCustomer([
            'member_id' => 'M-1261-ABCD',
            'name' => 'Customer Test',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
        ]);

        $this->assertCount(2, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertSame('M-1261-ABCD', $httpClient->posts[1]['data']['CardInfo']['cardNo']);
    }

    public function test_sync_customer_fans_out_one_job_per_hikvision_device(): void
    {
        $this->fakeHikvisionHttpClient(['xgym_entrance', 'xgym_exit']);
        Queue::fake([SyncCustomerToGateJob::class]);

        $deviceNames = $this->dispatchCustomer([
            'member_id' => 'M-FANOUT',
            'name' => 'Fan Out Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
        ]);

        $this->assertSame(['xgym_entrance', 'xgym_exit'], $deviceNames);

        Queue::assertPushed(SyncCustomerToGateJob::class, 2);
        Queue::assertPushed(
            SyncCustomerToGateJob::class,
            fn (SyncCustomerToGateJob $job) => $job->memberId() === 'M-FANOUT'
                && $job->deviceName() === 'xgym_entrance'
        );
        Queue::assertPushed(
            SyncCustomerToGateJob::class,
            fn (SyncCustomerToGateJob $job) => $job->memberId() === 'M-FANOUT'
                && $job->deviceName() === 'xgym_exit'
        );

        $this->getJson('/admin/status/M-FANOUT')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.devices.xgym_entrance.status', 'pending')
            ->assertJsonPath('data.devices.xgym_exit.status', 'pending');
    }

    public function test_one_gate_failure_does_not_overwrite_a_successful_gate_status(): void
    {
        $this->fakeHikvisionHttpClient(['xgym_entrance', 'xgym_exit'])
            ->withFailingHost('10.0.0.11');

        $statusStore = app(CustomerGateSyncStatusStore::class);
        $statusStore->begin(
            'M-PARTIAL',
            'customer.updated',
            ['xgym_entrance', 'xgym_exit']
        );

        $customer = CloudCustomerData::fromArray([
            'member_id' => 'M-PARTIAL',
            'name' => 'Partial Sync Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
        ]);

        $this->runGateJob(new SyncCustomerToGateJob(
            'M-PARTIAL',
            'xgym_entrance',
            'customer.updated',
            $customer
        ));

        $failedJob = new SyncCustomerToGateJob(
            'M-PARTIAL',
            'xgym_exit',
            'customer.updated',
            $customer
        );

        $failure = null;

        try {
            $this->runGateJob($failedJob);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'The simulated gate failure did not throw an exception.');
        $failedJob->failed($failure);

        $this->assertSame(3, $failedJob->tries);
        $this->assertSame([10, 60], $failedJob->backoff());

        $this->getJson('/admin/status/M-PARTIAL')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.devices.xgym_entrance.status', 'success')
            ->assertJsonPath('data.devices.xgym_exit.status', 'failed')
            ->assertJsonPath('data.devices.xgym_exit.attempt', 1)
            ->assertJsonPath(
                'data.devices.xgym_exit.last_error',
                'Simulated Hikvision connection failure for 10.0.0.11'
            );
    }

    public function test_sync_customer_updates_existing_hikvision_customer_instead_of_adding_duplicate(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient()
            ->withExistingPerson('M-EXISTING')
            ->withExistingCard('M-EXISTING');

        $this->dispatchCustomer([
            'member_id' => 'M-EXISTING',
            'name' => 'Existing Customer Updated',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'card_no' => 'CARD-UPDATED',
        ]);

        $this->assertCount(2, $httpClient->searches);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Search', $httpClient->searches[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Search', $httpClient->searches[1]['uri']);

        $this->assertCount(0, $httpClient->posts);
        $this->assertCount(2, $httpClient->puts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Modify', $httpClient->puts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Modify', $httpClient->puts[1]['uri']);
        $this->assertSame('M-EXISTING', $httpClient->puts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Existing Customer Updated', $httpClient->puts[0]['data']['UserInfo']['name']);
        $this->assertSame('CARD-UPDATED', $httpClient->puts[1]['data']['CardInfo']['cardNo']);
    }

    public function test_sync_customer_calls_face_endpoint_for_each_face_sample(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        $this->dispatchCustomer([
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

        $this->assertCount(2, $httpClient->posts);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Record', $httpClient->posts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Record', $httpClient->posts[1]['uri']);
        $this->assertCount(3, $httpClient->postMultiparts);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FaceDataRecord', $httpClient->postMultiparts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FaceDataRecord', $httpClient->postMultiparts[1]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FaceDataRecord', $httpClient->postMultiparts[2]['uri']);

        $frontFaceRecord = json_decode($httpClient->postMultiparts[0]['multipart'][0]['contents'], true);
        $leftFaceRecord = json_decode($httpClient->postMultiparts[1]['multipart'][0]['contents'], true);
        $rightFaceRecord = json_decode($httpClient->postMultiparts[2]['multipart'][0]['contents'], true);

        $this->assertSame('M-1262-WXYZ_1', $frontFaceRecord['FPID']);
        $this->assertSame('M-1262-WXYZ_2', $leftFaceRecord['FPID']);
        $this->assertSame('M-1262-WXYZ_3', $rightFaceRecord['FPID']);
        $this->assertSame('base64-front', $httpClient->postMultiparts[0]['multipart'][1]['contents']);
        $this->assertSame('base64-left', $httpClient->postMultiparts[1]['multipart'][1]['contents']);
        $this->assertSame('base64-right', $httpClient->postMultiparts[2]['multipart'][1]['contents']);
    }

    public function test_sync_customer_replaces_existing_face_records_by_stable_fpid(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient()
            ->withExistingFace('M-RETAKE_1')
            ->withExistingFace('M-RETAKE_2')
            ->withExistingFace('M-RETAKE_3');

        $this->dispatchCustomer([
            'member_id' => 'M-RETAKE',
            'name' => 'Retake Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'face_images_base64' => [
                'new-front',
                'new-left',
                'new-right',
            ],
        ]);

        $this->assertCount(0, $httpClient->postMultiparts);
        $this->assertCount(3, $httpClient->putMultiparts);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FDModify', $httpClient->putMultiparts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FDModify', $httpClient->putMultiparts[1]['uri']);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FDModify', $httpClient->putMultiparts[2]['uri']);

        $frontFaceRecord = json_decode($httpClient->putMultiparts[0]['multipart'][0]['contents'], true);
        $leftFaceRecord = json_decode($httpClient->putMultiparts[1]['multipart'][0]['contents'], true);
        $rightFaceRecord = json_decode($httpClient->putMultiparts[2]['multipart'][0]['contents'], true);

        $this->assertSame('M-RETAKE_1', $frontFaceRecord['FPID']);
        $this->assertSame('M-RETAKE_2', $leftFaceRecord['FPID']);
        $this->assertSame('M-RETAKE_3', $rightFaceRecord['FPID']);
        $this->assertSame('new-front', $httpClient->putMultiparts[0]['multipart'][1]['contents']);
        $this->assertSame('new-left', $httpClient->putMultiparts[1]['multipart'][1]['contents']);
        $this->assertSame('new-right', $httpClient->putMultiparts[2]['multipart'][1]['contents']);
    }

    public function test_legacy_sync_customer_routes_are_not_available(): void
    {
        $this->postJson('/api/sync-customer')->assertNotFound();
        $this->patchJson('/api/sync-customer/M-LEGACY')->assertNotFound();
        $this->deleteJson('/api/sync-customer/M-LEGACY')->assertNotFound();
    }

    private function dispatchCustomer(array $customer): array
    {
        return app(CustomerGateSyncDispatcher::class)->dispatch(
            $customer['member_id'],
            'customer.updated',
            CloudCustomerData::fromArray($customer)
        );
    }

    private function runGateJob(SyncCustomerToGateJob $job): void
    {
        $job->handle(
            app(CloudCustomerClient::class),
            app(CustomerGatePayloadBuilder::class),
            app(CustomerGateSyncService::class),
            app(CustomerGateSyncStatusStore::class)
        );
    }
}
