<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NtopNg extends Command
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
    public function handle()
    {
        IpAddress::query()->chunk(20, function(Collection $chunk) {
            foreach ($chunk as $ip) {
                /**
                 * @var $ip IpAddress
                 */
                Log::debug("[{$ip->address}] Updating usage");
                $this->output->writeln("[{$ip->address}] Updating usage");
                $ip->updateUsage();
            }
        });
    }
}
