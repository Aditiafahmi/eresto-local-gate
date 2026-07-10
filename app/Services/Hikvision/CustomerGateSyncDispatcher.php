<?php

namespace App\Services\Hikvision;

use App\DTOs\CloudCustomerData;
use App\Jobs\SyncCustomerToGatesJob;
use RuntimeException;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;

class CustomerGateSyncDispatcher
{
    public function __construct(
        private readonly CustomerGateSyncStatusStore $statusStore
    ) {}

    public function dispatch(string $memberId, string $event, ?CloudCustomerData $customer = null): array
    {
        $deviceNames = Hikvision::availableDevices();

        if ($deviceNames === []) {
            throw new RuntimeException('No Hikvision devices are configured.');
        }

        $this->statusStore->begin($memberId, $event, $deviceNames);

        SyncCustomerToGatesJob::dispatch(
            $memberId,
            $deviceNames,
            $event,
            $customer
        );

        return $deviceNames;
    }
}
