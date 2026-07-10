<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudCustomerClient;
use App\Services\Cloud\CustomerDeltaSyncStateStore;
use App\Services\Hikvision\CustomerGateSyncDispatcher;
use Illuminate\Console\Command;
use Throwable;

class SyncCustomerDeltaCommand extends Command
{
    protected $signature = 'sync:delta
                            {--reset : Ignore the saved cursor and request a full delta}';

    protected $description = 'Fetch changed Cloud customers and queue per-gate synchronization jobs';

    public function handle(
        CloudCustomerClient $cloudCustomerClient,
        CustomerGateSyncDispatcher $syncDispatcher,
        CustomerDeltaSyncStateStore $stateStore
    ): int {
        $lock = $stateStore->lock();

        if (! $lock->get()) {
            $this->warn('Customer delta sync is already running; skipping this execution.');

            return self::SUCCESS;
        }

        try {
            $since = $this->option('reset') ? null : $stateStore->cursor();
            $delta = $cloudCustomerClient->delta($since);
            $changes = $delta->latestChanges();

            foreach ($changes as $change) {
                $syncDispatcher->dispatch($change->memberId, $change->event);
            }

            $stateStore->saveCursor($delta->nextCursor);

            $this->info(sprintf(
                'Queued %d customer change(s). Cursor advanced to [%s].',
                count($changes),
                $delta->nextCursor
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Customer delta sync failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
