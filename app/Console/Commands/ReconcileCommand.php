<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\RateLimitingInterface;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    /** @var array<string, class-string<CaptivePortalInterface|RateLimitingInterface|DnsFilteringInterface>> */
    private const TARGETS = [
        'internet' => CaptivePortalInterface::class,
        'rate-limits' => RateLimitingInterface::class,
        'dns-filtering' => DnsFilteringInterface::class,
    ];

    protected $signature = 'aperture:reconcile {target : One of: internet, rate-limits, dns-filtering} {--dry-run}';

    protected $description = 'Reconcile internet, rate limit or DNS filtering state with its backend';

    public function handle(): int
    {
        $target = (string) $this->argument('target');

        if (! isset(self::TARGETS[$target])) {
            $this->error(sprintf(
                'Unknown target "%s". Valid targets: %s.',
                $target,
                implode(', ', array_keys(self::TARGETS)),
            ));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $this->laravel->make(self::TARGETS[$target])->reconcile($dryRun);

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
