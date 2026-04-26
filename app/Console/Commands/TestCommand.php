<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\Interfaces\HostStatsProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function handle(HostStatsProviderInterface $hostStats): void
    {
        $ip = new IpAddress;
        $ip->address = '10.30.0.197';

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
}
