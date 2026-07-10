<?php

namespace Tests\Feature;

use App\Jobs\SyncCustomerToGatesJob;
use App\Services\Cloud\CustomerDeltaSyncStateStore;
use App\Services\Hikvision\CustomerGateSyncDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncCustomerDeltaCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.eresto_cloud.base_url' => 'https://cloud.example.test',
            'services.eresto_cloud.delta_sync.enabled' => true,
            'services.eresto_cloud.delta_sync.store' => 'array',
        ]);
    }

    public function test_it_queues_every_delta_change_and_saves_the_next_cursor(): void
    {
        Queue::fake();

        Http::fake([
            'https://cloud.example.test/api/customers/delta' => Http::response([
                'data' => [
                    $this->change('M-CREATED', 'customer.created', '2026-07-10T10:00:00Z'),
                    $this->change('M-UPDATED', 'customer.updated', '2026-07-10T10:01:00Z'),
                    $this->change('M-DELETED', 'customer.deleted', '2026-07-10T10:02:00Z'),
                ],
                'next_cursor' => 'cursor-003',
            ]),
        ]);

        $this->artisan('sync:delta')
            ->expectsOutput('Queued 3 customer change(s). Cursor advanced to [cursor-003].')
            ->assertSuccessful();

        Queue::assertPushed(SyncCustomerToGatesJob::class, 3);
        $this->assertQueuedCustomer('M-CREATED', 'customer.created');
        $this->assertQueuedCustomer('M-UPDATED', 'customer.updated');
        $this->assertQueuedCustomer('M-DELETED', 'customer.deleted');
        $this->assertSame('cursor-003', app(CustomerDeltaSyncStateStore::class)->cursor());

        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example.test/api/customers/delta');
    }

    public function test_it_requests_changes_after_the_saved_cursor(): void
    {
        Queue::fake();
        app(CustomerDeltaSyncStateStore::class)->saveCursor('cursor-old');

        Http::fake([
            'https://cloud.example.test/api/customers/delta?since=cursor-old' => Http::response([
                'data' => [],
                'next_cursor' => 'cursor-new',
            ]),
        ]);

        $this->artisan('sync:delta')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url()
            === 'https://cloud.example.test/api/customers/delta?since=cursor-old');
        Queue::assertNothingPushed();
        $this->assertSame('cursor-new', app(CustomerDeltaSyncStateStore::class)->cursor());
    }

    public function test_it_does_not_advance_the_cursor_when_dispatch_fails(): void
    {
        app(CustomerDeltaSyncStateStore::class)->saveCursor('cursor-before-failure');

        Http::fake([
            'https://cloud.example.test/api/customers/delta?since=cursor-before-failure' => Http::response([
                'data' => [
                    $this->change('M-FIRST', 'customer.updated', '2026-07-10T10:00:00Z'),
                    $this->change('M-FAIL', 'customer.updated', '2026-07-10T10:01:00Z'),
                ],
                'next_cursor' => 'cursor-must-not-be-saved',
            ]),
        ]);

        $dispatcher = Mockery::mock(CustomerGateSyncDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('M-FIRST', 'customer.updated')
            ->andReturn(['xgym_entrance', 'xgym_exit']);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('M-FAIL', 'customer.updated')
            ->andThrow(new RuntimeException('Queue unavailable'));
        $this->app->instance(CustomerGateSyncDispatcher::class, $dispatcher);

        $this->artisan('sync:delta')
            ->expectsOutput('Customer delta sync failed: Queue unavailable')
            ->assertFailed();

        $this->assertSame(
            'cursor-before-failure',
            app(CustomerDeltaSyncStateStore::class)->cursor()
        );

        $lock = app(CustomerDeltaSyncStateStore::class)->lock();
        $this->assertTrue($lock->get(), 'The command did not release its delta lock after failure.');
        $lock->release();
    }

    public function test_it_dispatches_only_the_latest_event_for_the_same_customer(): void
    {
        Queue::fake();

        Http::fake([
            'https://cloud.example.test/api/customers/delta' => Http::response([
                'data' => [
                    $this->change('M-RECREATED', 'customer.deleted', '2026-07-10T10:00:00Z'),
                    $this->change('M-RECREATED', 'customer.created', '2026-07-10T10:01:00Z'),
                ],
                'next_cursor' => 'cursor-after-recreate',
            ]),
        ]);

        $this->artisan('sync:delta')
            ->expectsOutput('Queued 1 customer change(s). Cursor advanced to [cursor-after-recreate].')
            ->assertSuccessful();

        Queue::assertPushed(SyncCustomerToGatesJob::class, 1);
        $this->assertQueuedCustomer('M-RECREATED', 'customer.created');
        Queue::assertNotPushed(
            SyncCustomerToGatesJob::class,
            fn (SyncCustomerToGatesJob $job) => in_array('event:customer.deleted', $job->tags(), true)
        );
    }

    public function test_reset_ignores_the_saved_cursor(): void
    {
        Queue::fake();
        app(CustomerDeltaSyncStateStore::class)->saveCursor('cursor-to-ignore');

        Http::fake([
            'https://cloud.example.test/api/customers/delta' => Http::response([
                'data' => [],
                'next_cursor' => 'cursor-after-reset',
            ]),
        ]);

        $this->artisan('sync:delta', ['--reset' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url()
            === 'https://cloud.example.test/api/customers/delta');
        $this->assertSame(
            'cursor-after-reset',
            app(CustomerDeltaSyncStateStore::class)->cursor()
        );
    }

    public function test_it_skips_when_another_delta_sync_holds_the_lock(): void
    {
        Http::preventStrayRequests();
        $lock = app(CustomerDeltaSyncStateStore::class)->lock();
        $this->assertTrue($lock->get());

        try {
            $this->artisan('sync:delta')
                ->expectsOutput('Customer delta sync is already running; skipping this execution.')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    private function change(string $memberId, string $event, string $modifiedAt): array
    {
        return [
            'member_id' => $memberId,
            'event' => $event,
            'modified_at' => $modifiedAt,
        ];
    }

    private function assertQueuedCustomer(string $memberId, string $event): void
    {
        Queue::assertPushed(
            SyncCustomerToGatesJob::class,
            fn (SyncCustomerToGatesJob $job) => in_array('customer:'.$memberId, $job->tags(), true)
                && in_array('event:'.$event, $job->tags(), true)
        );
    }
}
