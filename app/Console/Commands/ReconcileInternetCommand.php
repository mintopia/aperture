<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\FirewallBackendInterface;
use Illuminate\Console\Command;

class ReconcileInternetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:reconcile-internet {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile internet access state with the firewall backend';

    /**
     * Execute the console command.
     */
    public function handle(FirewallBackendInterface $firewall): int
    {
        $dryRun = $this->option('dry-run');
        $result = $firewall->reconcileInternet((bool) $dryRun);

        if ($dryRun) {
            $this->info('[DRY RUN] No changes applied.');
        }

        $this->info(sprintf(
            'Added: %d, Removed: %d, Unchanged: %d, Errors: %d',
            count($result->added),
            count($result->removed),
            count($result->unchanged),
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
