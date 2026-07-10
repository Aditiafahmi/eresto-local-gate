<?php

namespace Tests\Feature;

use App\DTOs\CloudCustomerData;
use App\Services\Cloud\CloudCustomerClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CloudCustomerClientTest extends TestCase
{
    public function test_it_returns_a_validated_local_customer_dto(): void
    {
        config(['services.eresto_cloud.base_url' => 'https://cloud.example.test']);

        Http::fake([
            'https://cloud.example.test/api/customers/M-DTO-CLIENT' => Http::response([
                'data' => [
                    'member_id' => 'M-DTO-CLIENT',
                    'name' => 'Cloud DTO Customer',
                    'start_date' => '2026-07-07T00:00:00',
                    'end_date' => '2026-12-31T23:59:59',
                    'cloud_only_field' => 'ignored',
                ],
            ]),
        ]);

        $customer = app(CloudCustomerClient::class)->findCustomer('M-DTO-CLIENT');

        $this->assertInstanceOf(CloudCustomerData::class, $customer);
        $this->assertSame('M-DTO-CLIENT', $customer->memberId);
        $this->assertSame('Cloud DTO Customer', $customer->name);
    }

    public function test_it_rejects_an_incomplete_cloud_customer_response(): void
    {
        config(['services.eresto_cloud.base_url' => 'https://cloud.example.test']);

        Http::fake([
            'https://cloud.example.test/api/customers/M-INCOMPLETE' => Http::response([
                'data' => [
                    'member_id' => 'M-INCOMPLETE',
                ],
            ]),
        ]);

        $this->expectException(ValidationException::class);

        app(CloudCustomerClient::class)->findCustomer('M-INCOMPLETE');
    }
}
