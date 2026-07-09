<?php

namespace App\Jobs;

use App\Services\Cloud\CloudCustomerClient;
use App\Services\Hikvision\CustomerGatePayloadBuilder;
use App\Services\Hikvision\CustomerGateSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCustomerToGatesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly string $memberId,
        private readonly string $event = 'customer.updated',
        private readonly ?array $customer = null
    ) {
        $this->onQueue('hikvision-sync');
    }

    public function handle(
        CloudCustomerClient $cloudCustomerClient,
        CustomerGatePayloadBuilder $payloadBuilder,
        CustomerGateSyncService $gateSyncService
    ): void {
        if ($this->event === 'customer.deleted') {
            $gateSyncService->delete($this->memberId);

            return;
        }

        $customer = $this->customer ?? $cloudCustomerClient->findCustomer($this->memberId);

        $gateSyncService->sync(
            $payloadBuilder->build($customer)
        );
    }

    public function backoff(): array
    {
        return [10, 60, 300];
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
