<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\DnsFilteringInterface;
use Illuminate\Console\Command;

class ReconcileDnsFilteringCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aperture:reconcile-dns-filtering {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile DNS filtering state with the DNS filtering backend';

    /**
     * Execute the console command.
     */
    public function handle(DnsFilteringInterface $dnsFiltering): int
    {
        $dryRun = $this->option('dry-run');
        $result = $dnsFiltering->reconcile((bool) $dryRun);

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
