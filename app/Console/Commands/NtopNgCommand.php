<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NtopNgCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:ntopng';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all IP addresses from ntopng';

    /**
     * Execute the console command.
     */
    public function handle(IpAddressActionService $service): void
    {
        IpAddress::query()->chunk(20, function (Collection $chunk) use ($service): void {
            foreach ($chunk as $ip) {
                /** @var IpAddress $ip */
                Log::debug(sprintf('[%s] Updating usage', $ip->address));
                $this->output->writeln(sprintf('[%s] Updating usage', $ip->address));
                $service->updateUsage($ip);
            }
        });
    }
}
