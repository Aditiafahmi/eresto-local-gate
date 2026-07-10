<?php

namespace App\Services\Cloud;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class CustomerDeltaSyncStateStore
{
    public function cursor(): ?string
    {
        $cursor = $this->cache()->get($this->cursorKey());

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function saveCursor(string $cursor): void
    {
        $this->cache()->forever($this->cursorKey(), $cursor);
    }

    public function forgetCursor(): void
    {
        $this->cache()->forget($this->cursorKey());
    }

    public function lock(): Lock
    {
        return $this->cache()->lock(
            $this->lockKey(),
            max((int) config('services.eresto_cloud.delta_sync.lock_seconds', 1800), 60)
        );
    }

    private function cache(): Repository
    {
        return Cache::store(config('services.eresto_cloud.delta_sync.store'));
    }

    private function cursorKey(): string
    {
        return (string) config(
            'services.eresto_cloud.delta_sync.cursor_key',
            'eresto:customer-delta:last-cursor'
        );
    }

    private function lockKey(): string
    {
        return (string) config(
            'services.eresto_cloud.delta_sync.lock_key',
            'eresto:customer-delta:lock'
        );
    }
}
