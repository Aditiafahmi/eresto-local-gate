<?php

namespace App\Services\Hikvision;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class CustomerGateSyncStatusStore
{
    private const KEY_PREFIX = 'hikvision:customer-sync:';

    public function begin(string $memberId, string $event, array $deviceNames): void
    {
        $now = now()->toIso8601String();

        $this->cache()->put($this->metaKey($memberId), [
            'member_id' => $memberId,
            'event' => $event,
            'device_names' => array_values($deviceNames),
            'queued_at' => $now,
            'updated_at' => $now,
        ], $this->ttl());

        foreach ($deviceNames as $deviceName) {
            $this->putDeviceStatus($memberId, $deviceName, [
                'device' => $deviceName,
                'status' => 'pending',
                'attempt' => 0,
                'queued_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function markProcessing(string $memberId, string $deviceName, int $attempt): void
    {
        $this->updateDeviceStatus($memberId, $deviceName, [
            'status' => 'processing',
            'attempt' => $attempt,
            'started_at' => now()->toIso8601String(),
            'last_error' => null,
        ]);
    }

    public function markRetrying(string $memberId, string $deviceName, int $attempt, string $error): void
    {
        $this->updateDeviceStatus($memberId, $deviceName, [
            'status' => 'retrying',
            'attempt' => $attempt,
            'last_error' => $error,
        ]);
    }

    public function markSuccess(string $memberId, string $deviceName, int $attempt, array $result): void
    {
        $this->updateDeviceStatus($memberId, $deviceName, [
            'status' => 'success',
            'attempt' => $attempt,
            'result' => $result,
            'last_error' => null,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function markFailed(string $memberId, string $deviceName, int $attempt, string $error): void
    {
        $current = $this->cache()->get($this->deviceKey($memberId, $deviceName));

        if (is_array($current) && ($current['status'] ?? null) === 'success') {
            return;
        }

        $this->updateDeviceStatus($memberId, $deviceName, [
            'status' => 'failed',
            'attempt' => $attempt,
            'last_error' => $error,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function get(string $memberId): ?array
    {
        $meta = $this->cache()->get($this->metaKey($memberId));

        if (! is_array($meta)) {
            return null;
        }

        $devices = [];

        foreach ($meta['device_names'] ?? [] as $deviceName) {
            $status = $this->cache()->get($this->deviceKey($memberId, $deviceName));
            $devices[$deviceName] = is_array($status) ? $status : [
                'device' => $deviceName,
                'status' => 'pending',
                'attempt' => 0,
            ];
        }

        unset($meta['device_names']);

        return [
            ...$meta,
            'status' => $this->overallStatus($devices),
            'devices' => $devices,
        ];
    }

    private function updateDeviceStatus(string $memberId, string $deviceName, array $changes): void
    {
        $current = $this->cache()->get($this->deviceKey($memberId, $deviceName), [
            'device' => $deviceName,
            'queued_at' => now()->toIso8601String(),
        ]);

        $this->putDeviceStatus($memberId, $deviceName, [
            ...$current,
            ...$changes,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    private function putDeviceStatus(string $memberId, string $deviceName, array $status): void
    {
        $this->cache()->put(
            $this->deviceKey($memberId, $deviceName),
            $status,
            $this->ttl()
        );

        $meta = $this->cache()->get($this->metaKey($memberId));

        if (is_array($meta)) {
            $meta['updated_at'] = now()->toIso8601String();
            $this->cache()->put($this->metaKey($memberId), $meta, $this->ttl());
        }
    }

    private function overallStatus(array $devices): string
    {
        $statuses = array_column($devices, 'status');

        if ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'success') {
            return 'success';
        }

        foreach (['failed', 'retrying', 'processing'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'pending';
    }

    private function cache(): Repository
    {
        return Cache::store(config('hikvision.sync_status.store'));
    }

    private function ttl(): int
    {
        return max((int) config('hikvision.sync_status.ttl', 604800), 60);
    }

    private function metaKey(string $memberId): string
    {
        return self::KEY_PREFIX.hash('sha256', $memberId).':meta';
    }

    private function deviceKey(string $memberId, string $deviceName): string
    {
        return self::KEY_PREFIX.hash('sha256', $memberId).':device:'.hash('sha256', $deviceName);
    }
}
