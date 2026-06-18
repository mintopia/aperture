<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncDhcpData;
use Illuminate\Console\Command;

class SyncDhcpOnce extends Command
{
    protected $signature = 'dhcp:sync';

    protected $description = 'Run a DHCP data sync immediately';

    public function handle(): int
    {
        SyncDhcpData::dispatchSync();
        $this->info('DHCP sync completed.');

        return self::SUCCESS;
    }
}
