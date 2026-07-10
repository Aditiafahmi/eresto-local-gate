<?php

namespace App\Jobs;

use App\DTOs\CloudCustomerData;
use App\Services\Cloud\CloudCustomerClient;
use App\Services\Hikvision\CustomerGatePayloadBuilder;
use App\Services\Hikvision\CustomerGateSyncService;
use App\Services\Hikvision\CustomerGateSyncStatusStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCustomerToGateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly string $memberId,
        private readonly string $deviceName,
        private readonly string $event = 'customer.updated',
        private readonly ?CloudCustomerData $customer = null
    ) {
        $this->onQueue('hikvision-sync');
    }

    public function handle(
        CloudCustomerClient $cloudCustomerClient,
        CustomerGatePayloadBuilder $payloadBuilder,
        CustomerGateSyncService $gateSyncService,
        CustomerGateSyncStatusStore $statusStore
    ): void {
        $attempt = max($this->attempts(), 1);
        $statusStore->markProcessing($this->memberId, $this->deviceName, $attempt);

        try {
            if ($this->event === 'customer.deleted') {
                $result = $gateSyncService->deleteFromDevice($this->deviceName, $this->memberId);
            } else {
                $customer = $this->customer ?? $cloudCustomerClient->findCustomer($this->memberId);
                $result = $gateSyncService->syncDevice(
                    $this->deviceName,
                    $payloadBuilder->build($customer)
                );
            }
        } catch (Throwable $exception) {
            $statusStore->markRetrying(
                $this->memberId,
                $this->deviceName,
                $attempt,
                $exception->getMessage()
            );

            throw $exception;
        }

        $statusStore->markSuccess(
            $this->memberId,
            $this->deviceName,
            $attempt,
            $result
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(CustomerGateSyncStatusStore::class)->markFailed(
            $this->memberId,
            $this->deviceName,
            max($this->attempts(), 1),
            $exception?->getMessage() ?? 'The gate sync job failed.'
        );
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
            'device:'.$this->deviceName,
        ];
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function deviceName(): string
    {
        return $this->deviceName;
    }
}
