<?php

namespace App\Jobs;

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
        private readonly array $customer
    ) {
        $this->onQueue('hikvision-sync');
    }

    public function handle(
        CustomerGatePayloadBuilder $payloadBuilder,
        CustomerGateSyncService $gateSyncService
    ): void {
        $gateSyncService->sync(
            $payloadBuilder->build($this->customer)
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
            'customer:'.$this->customer['member_id'],
        ];
    }
}
