<?php

use App\Jobs\HorizonDemoJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('queue:demo-horizon {count=25 : Number of demo jobs to dispatch} {--sleep=5 : Seconds each job should sleep} {--queue=hikvision-sync : Queue name}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('This demo command is only available in local/testing environments.');

        return 1;
    }

    $count = min(max((int) $this->argument('count'), 1), 500);
    $sleepSeconds = min(max((int) $this->option('sleep'), 0), 60);
    $queue = (string) $this->option('queue');
    $batchId = (string) Str::uuid();

    foreach (range(1, $count) as $number) {
        HorizonDemoJob::dispatch($batchId, $number, $sleepSeconds)
            ->onQueue($queue);
    }

    $this->info("Dispatched {$count} demo jobs to queue [{$queue}].");
    $this->line("Batch tag: batch:{$batchId}");

    return 0;
})->purpose('Dispatch safe demo jobs so Horizon can be tested locally');
