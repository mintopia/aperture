<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\ReapplyAccessRules;
use App\Jobs\ScanNetworkDevices;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('aperture:expire-sessions')->everyFiveMinutes();
        $schedule->command('aperture:ntopng')->everyFiveMinutes();
        $schedule->job(new ReapplyAccessRules)->everyFifteenMinutes();
        $schedule->job(new ScanNetworkDevices)->everyFiveMinutes();

        $interval = (int) config('aperture.switch_sync_interval', 5);

        $schedule->call(function (): void {
            SwitchConfig::where('enabled', true)->each(function (SwitchConfig $switch): void {
                SyncSwitchPortsJob::dispatch($switch);
            });
        })->cron(sprintf('*/%d * * * *', $interval))->name('sync-switch-ports')->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
