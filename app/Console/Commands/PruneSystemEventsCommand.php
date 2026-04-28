<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemEvent;
use Illuminate\Console\Command;

class PruneSystemEventsCommand extends Command
{
    protected $signature = 'events:prune';

    protected $description = 'Delete system events older than the configured retention period';

    public function handle(): int
    {
        $days = (int) config('events.retention_days', 30);
        $cutoff = now()->subDays($days);

        $deleted = 0;

        do {
            $batch = SystemEvent::where('created_at', '<', $cutoff)
                ->limit(1000)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Pruned %s system events older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}
