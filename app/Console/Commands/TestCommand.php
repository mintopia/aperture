<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Quick Testing';

    /**
     * Execute the console command.
     */
    public function handle(IpAddressActionService $service): void
    {
        $ip = new IpAddress;
        $ip->address = '10.30.0.197';

        $service->updateUsage($ip);
    }
}
