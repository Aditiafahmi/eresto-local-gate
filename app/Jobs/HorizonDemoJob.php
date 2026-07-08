<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HorizonDemoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        private readonly string $batchId,
        private readonly int $number,
        private readonly int $sleepSeconds
    ) {
        //
    }

    public function handle(): void
    {
        sleep(max(0, $this->sleepSeconds));

        Log::info('Horizon demo job completed', [
            'batch_id' => $this->batchId,
            'number' => $this->number,
        ]);
    }

    public function tags(): array
    {
        return [
            'demo',
            'batch:'.$this->batchId,
            'job:'.$this->number,
        ];
    }
}
