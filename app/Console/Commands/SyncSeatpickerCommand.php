<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seatpicker\SeatpickerSyncService;
use Illuminate\Console\Command;

class SyncSeatpickerCommand extends Command
{
    protected $signature = 'aperture:sync-seatpicker';

    protected $description = 'Sync seat assignments from the seatpicker integration';

    public function handle(SeatpickerSyncService $syncService): int
    {
        $result = $syncService->sync();

        if ($result->success) {
            $this->info($result->message);

            return self::SUCCESS;
        }

        $this->error($result->message);

        return self::FAILURE;
    }
}
