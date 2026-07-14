<?php

namespace Tests\Feature;

use App\Jobs\SyncCustomerToGatesJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesHikvisionHttpClient;
use Tests\TestCase;

class CloudWebhookTest extends TestCase
{
    use FakesHikvisionHttpClient;

    public function test_cloud_webhook_accepts_customer_created_event(): void
    {
        $this->fakeHikvisionHttpClient();
        Queue::fake();

        config([
            'services.eresto_cloud.webhook_secret' => null,
        ]);

        $this->postJson('/cloud/webhook', [
            'event' => 'customer.created',
            'member_id' => 'M-CREATE',
        ])->assertStatus(202)
            ->assertJsonPath('message', 'Webhook accepted')
            ->assertJsonPath('event', 'customer.created')
            ->assertJsonPath('member_id', 'M-CREATE');

        Queue::assertPushed(
            SyncCustomerToGatesJob::class,
            fn (SyncCustomerToGatesJob $job) => in_array('event:customer.created', $job->tags(), true)
                && in_array('customer:M-CREATE', $job->tags(), true)
        );
    }

    public function test_cloud_webhook_fetches_customer_from_cloud_and_syncs_to_hikvision(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        config([
            'services.eresto_cloud.base_url' => 'https://cloud.example.test',
            'services.eresto_cloud.webhook_secret' => null,
        ]);

        Http::fake([
            'https://cloud.example.test/api/customers/M-123' => Http::response([
                'member_id' => 'M-123',
                'name' => 'Webhook Customer',
                'start_date' => '2026-07-08T00:00:00',
                'end_date' => '2026-12-31T23:59:59',
                'status' => 'active',
                'card_no' => 'CARD-M-123',
                'face_images_base64' => ['base64-webhook-face'],
            ]),
        ]);

        $response = $this->postJson('/cloud/webhook', [
            'event' => 'customer.updated',
            'member_id' => 'M-123',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Webhook accepted')
            ->assertJsonPath('event', 'customer.updated')
            ->assertJsonPath('member_id', 'M-123');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://cloud.example.test/api/customers/M-123');

        $this->assertCount(2, $httpClient->posts);
        $this->assertSame('M-123', $httpClient->posts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Webhook Customer', $httpClient->posts[0]['data']['UserInfo']['name']);
        $this->assertSame('CARD-M-123', $httpClient->posts[1]['data']['CardInfo']['cardNo']);
        $this->assertCount(1, $httpClient->postMultiparts);
        $this->assertStringContainsString('/ISAPI/Intelligent/FDLib/FaceDataRecord', $httpClient->postMultiparts[0]['uri']);

        $faceRecord = json_decode($httpClient->postMultiparts[0]['multipart'][0]['contents'], true);
        $this->assertSame('M-123', $faceRecord['FPID']);
        $this->assertSame('base64-webhook-face', $httpClient->postMultiparts[0]['multipart'][1]['contents']);
    }

    public function test_cloud_webhook_deletes_customer_from_hikvision_without_fetching_cloud(): void
    {
        $httpClient = $this->fakeHikvisionHttpClient();

        config([
            'services.eresto_cloud.base_url' => 'https://cloud.example.test',
            'services.eresto_cloud.webhook_secret' => null,
        ]);

        Http::fake();

        $response = $this->postJson('/cloud/webhook', [
            'event' => 'customer.deleted',
            'member_id' => 'M-DELETE',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Webhook accepted')
            ->assertJsonPath('event', 'customer.deleted')
            ->assertJsonPath('member_id', 'M-DELETE');

        Http::assertNothingSent();

        $this->assertCount(2, $httpClient->puts);
        $this->assertStringContainsString('/ISAPI/AccessControl/CardInfo/Delete', $httpClient->puts[0]['uri']);
        $this->assertStringContainsString('/ISAPI/AccessControl/UserInfo/Delete', $httpClient->puts[1]['uri']);
        $this->assertSame(
            'M-DELETE',
            $httpClient->puts[0]['data']['CardInfoDelCond']['EmployeeNoList'][0]['employeeNo']
        );
        $this->assertSame(
            'M-DELETE',
            $httpClient->puts[1]['data']['UserInfoDelCond']['EmployeeNoList'][0]['employeeNo']
        );
    }

    public function test_cloud_webhook_rejects_invalid_signature_when_secret_is_configured(): void
    {
        config([
            'services.eresto_cloud.webhook_secret' => 'shared-secret',
        ]);

        $response = $this->postJson('/cloud/webhook', [
            'event' => 'customer.updated',
            'member_id' => 'M-123',
        ]);

        $response->assertUnauthorized();
    }
}
