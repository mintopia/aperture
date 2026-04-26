<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\Interfaces\HostStatsProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBandwidthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:sync-bandwidth';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update bandwidth usage totals for all IP addresses';

    /**
     * Execute the console command.
     */
    public function handle(HostStatsProviderInterface $hostStats): void
    {
        IpAddress::query()->chunk(20, function (Collection $chunk) use ($hostStats): void {
            foreach ($chunk as $ip) {
                /** @var IpAddress $ip */
                Log::debug(sprintf('[%s] Updating usage', $ip->address));
                $this->output->writeln(sprintf('[%s] Updating usage', $ip->address));

                try {
                    $bytes = $hostStats->getHostBytes($ip->address);
                    if ($bytes !== null) {
                        $ip->received = $bytes->received;
                        $ip->sent = $bytes->sent;
                        $ip->save();
                    }
                } catch (Throwable $e) {
                    Log::warning('Failed to update usage for IP address', [
                        'ip' => $ip->address,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
