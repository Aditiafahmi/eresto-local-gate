<?php

namespace Tests\Unit;

use App\DTOs\CloudCustomerData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CloudCustomerDataTest extends TestCase
{
    public function test_it_applies_defaults_and_ignores_extra_cloud_fields(): void
    {
        $customer = CloudCustomerData::fromArray([
            'member_id' => 'M-DTO-001',
            'name' => 'DTO Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
            'unused_cloud_field' => 'ignored',
        ]);

        $this->assertSame('M-DTO-001', $customer->memberId);
        $this->assertSame('DTO Customer', $customer->name);
        $this->assertSame('active', $customer->status);
        $this->assertTrue($customer->accessEnabled());
        $this->assertNull($customer->cardNo);
        $this->assertSame('M-DTO-001', $customer->cardNumber());
        $this->assertSame([], $customer->faceImagesBase64);
    }

    public function test_it_rejects_invalid_dates_status_and_face_images(): void
    {
        try {
            CloudCustomerData::fromArray([
                'member_id' => 'M-DTO-INVALID',
                'name' => 'Invalid DTO Customer',
                'start_date' => '2026-12-31T23:59:59',
                'end_date' => '2026-01-01T00:00:00',
                'status' => 'blocked',
                'face_images_base64' => [''],
            ]);

            $this->fail('Invalid Cloud customer data was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertArrayHasKey('face_images_base64.0', $exception->errors());
        }
    }

    public function test_it_rejects_a_member_id_that_differs_from_the_requested_customer(): void
    {
        $this->expectException(ValidationException::class);

        CloudCustomerData::fromArray([
            'member_id' => 'M-WRONG',
            'name' => 'Wrong Customer',
            'start_date' => '2026-07-07T00:00:00',
            'end_date' => '2026-12-31T23:59:59',
        ], 'M-EXPECTED');
    }
}
