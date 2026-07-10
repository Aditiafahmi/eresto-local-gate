<?php

namespace App\Jobs;

use App\DTOs\CloudCustomerData;
use App\Services\Cloud\CloudCustomerClient;
use App\Services\Hikvision\CustomerGateSyncStatusStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCustomerToGatesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly string $memberId,
        private readonly array $deviceNames,
        private readonly string $event = 'customer.updated',
        private readonly ?CloudCustomerData $customer = null
    ) {
        $this->onQueue('hikvision-sync');
    }

    public function handle(CloudCustomerClient $cloudCustomerClient): void
    {
        $customer = $this->event === 'customer.deleted'
            ? null
            : ($this->customer ?? $cloudCustomerClient->findCustomer($this->memberId));

        foreach ($this->deviceNames as $deviceName) {
            SyncCustomerToGateJob::dispatch(
                $this->memberId,
                $deviceName,
                $this->event,
                $customer
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $statusStore = app(CustomerGateSyncStatusStore::class);

        foreach ($this->deviceNames as $deviceName) {
            $statusStore->markFailed(
                $this->memberId,
                $deviceName,
                max($this->attempts(), 1),
                $exception?->getMessage() ?? 'The customer sync fan-out job failed.'
            );
        }
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function tags(): array
    {
        return [
            'hikvision',
            'event:'.$this->event,
            'customer:'.$this->memberId,
        ];
    }
}
