<?php

namespace Tests\Unit;

use App\DTOs\CloudCustomerDeltaData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CloudCustomerDeltaDataTest extends TestCase
{
    public function test_it_builds_a_typed_delta_contract(): void
    {
        $delta = CloudCustomerDeltaData::fromArray([
            'data' => [
                [
                    'member_id' => 'M-DELTA-001',
                    'event' => 'customer.updated',
                    'modified_at' => '2026-07-10T10:00:00Z',
                ],
            ],
            'next_cursor' => 'cursor-002',
        ]);

        $this->assertSame('cursor-002', $delta->nextCursor);
        $this->assertCount(1, $delta->changes);
        $this->assertSame('M-DELTA-001', $delta->changes[0]->memberId);
        $this->assertSame('customer.updated', $delta->changes[0]->event);
        $this->assertSame('2026-07-10T10:00:00Z', $delta->changes[0]->modifiedAt);
    }

    public function test_it_rejects_an_invalid_delta_contract(): void
    {
        try {
            CloudCustomerDeltaData::fromArray([
                'data' => [
                    [
                        'member_id' => 'M-DELTA-INVALID',
                        'event' => 'customer.unknown',
                        'modified_at' => 'not-a-date',
                    ],
                ],
                'next_cursor' => '',
            ]);

            $this->fail('Invalid Cloud delta data was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.0.event', $exception->errors());
            $this->assertArrayHasKey('data.0.modified_at', $exception->errors());
            $this->assertArrayHasKey('next_cursor', $exception->errors());
        }
    }

    public function test_it_accepts_an_empty_delta_and_advances_the_cursor(): void
    {
        $delta = CloudCustomerDeltaData::fromArray([
            'data' => [],
            'next_cursor' => 'cursor-without-changes',
        ]);

        $this->assertSame([], $delta->changes);
        $this->assertSame('cursor-without-changes', $delta->nextCursor);
    }

    public function test_it_keeps_only_the_last_ordered_event_per_customer(): void
    {
        $delta = CloudCustomerDeltaData::fromArray([
            'data' => [
                [
                    'member_id' => 'M-RECREATED',
                    'event' => 'customer.deleted',
                    'modified_at' => '2026-07-10T10:00:00Z',
                ],
                [
                    'member_id' => 'M-OTHER',
                    'event' => 'customer.updated',
                    'modified_at' => '2026-07-10T10:01:00Z',
                ],
                [
                    'member_id' => 'M-RECREATED',
                    'event' => 'customer.created',
                    'modified_at' => '2026-07-10T10:02:00Z',
                ],
            ],
            'next_cursor' => 'cursor-recreated',
        ]);

        $changes = $delta->latestChanges();

        $this->assertCount(2, $changes);
        $this->assertSame('M-OTHER', $changes[0]->memberId);
        $this->assertSame('M-RECREATED', $changes[1]->memberId);
        $this->assertSame('customer.created', $changes[1]->event);
    }
}
