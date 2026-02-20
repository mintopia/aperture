<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\Firewalls\OpnSense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OpnSenseRecoveryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:opnsense-recovery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore IP addresses in the captive portal allow list after a reboot';

    /**
     * Execute the console command.
     */
    public function handle(OpnSense $opnSense)
    {
        $uptime = $opnSense->getUptime();
        $lastUptime = Cache::get('opnsense.uptime', 0);
        Cache::put('opnsense.uptime', $uptime);
        if ($uptime > 3600) {
            // Uptime is more than an hour, we can assume the system has been up for a while and there is no reboot
            $this->log("Uptime is {$uptime}, this is more than an hour, assuming no reboot");
            return self::SUCCESS;
        }
        if ($uptime > $lastUptime) {
            // All good, uptime is higher than last time, so no reboot
            $this->log("Uptime is {$uptime}, this is more than the last uptime");
            return self::SUCCESS;
        }

        $this->log("Uptime is {$uptime}, this is less than the last uptime, restoring IPs");
        $ips = IpAddress::all();
        foreach ($ips as $ip) {
            $this->processIp($ip);
        }
        return self::SUCCESS;
    }

    protected function log(string $message): void
    {
        $this->output->writeln($message);
        Log::info("[aperture:opnsense-recovery] {$message}");
    }

    protected function processIp(IpAddress $ip): void
    {
        foreach ($ip->users as $user) {
            if ($user->blocked) {
                $this->log("{$ip} is blocked, skipping");
                return;
            }
        }
        $ip->allow();
        $this->log("{$ip} has been allowed");
    }
}
