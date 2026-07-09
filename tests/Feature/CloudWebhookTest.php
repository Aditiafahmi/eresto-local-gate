<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\FakesHikvisionHttpClient;
use Tests\TestCase;

class CloudWebhookTest extends TestCase
{
    use FakesHikvisionHttpClient;

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

        $this->assertCount(3, $httpClient->posts);
        $this->assertSame('M-123', $httpClient->posts[0]['data']['UserInfo']['employeeNo']);
        $this->assertSame('Webhook Customer', $httpClient->posts[0]['data']['UserInfo']['name']);
        $this->assertSame('CARD-M-123', $httpClient->posts[1]['data']['CardInfo']['cardNo']);
        $this->assertSame('base64-webhook-face', $httpClient->posts[2]['data']['faceData']);
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
