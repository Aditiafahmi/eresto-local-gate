<?php

namespace Tests\Feature\Mock;

use Tests\TestCase;

class CloudApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mock.cloud.enabled' => true]);
    }

    public function test_it_returns_a_mock_customer(): void
    {
        $this->getJson('/mock-cloud/api/customers/M123')
            ->assertOk()
            ->assertJsonPath('data.member_id', 'M123')
            ->assertJsonPath('data.name', 'Mock Active Customer')
            ->assertJsonPath('data.card_no', 'CARD-M123');
    }

    public function test_it_returns_not_found_for_an_unknown_customer(): void
    {
        $this->getJson('/mock-cloud/api/customers/UNKNOWN')
            ->assertNotFound();
    }

    public function test_it_returns_delta_changes_then_an_empty_page_for_the_latest_cursor(): void
    {
        $initialResponse = $this->getJson('/mock-cloud/api/customers/delta')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.member_id', 'M123')
            ->assertJsonPath('next_cursor', 'mock-cursor-1');

        $cursor = $initialResponse->json('next_cursor');

        $this->getJson('/mock-cloud/api/customers/delta?since='.$cursor)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('next_cursor', 'mock-cursor-1');
    }

    public function test_it_accepts_a_mock_face_enrolment_confirmation(): void
    {
        $this->postJson('/mock-cloud/api/customers/M123/enrol-face')
            ->assertOk()
            ->assertJsonPath('member_id', 'M123')
            ->assertJsonPath('face_enrolled', true);
    }

    public function test_it_is_not_available_when_disabled(): void
    {
        config(['mock.cloud.enabled' => false]);

        $this->getJson('/mock-cloud/api/customers/M123')
            ->assertNotFound();
    }
}
