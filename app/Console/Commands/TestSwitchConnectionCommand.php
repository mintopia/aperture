<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\SwitchConnectionTester;
use Illuminate\Console\Command;

class TestSwitchConnectionCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aperture:test-switch-connection {switch : The ID or hostname of the switch config}';

    /**
     * @var string
     */
    protected $description = 'Test connectivity to a configured network switch';

    public function handle(SwitchConnectionTester $tester): int
    {
        $identifier = $this->argument('switch');

        $switchConfig = is_numeric($identifier)
            ? SwitchConfig::find((int) $identifier)
            : SwitchConfig::where('hostname', $identifier)->first();

        if (! $switchConfig instanceof SwitchConfig) {
            $this->error(sprintf('Switch config not found: %s', $identifier));

            return Command::FAILURE;
        }

        $this->info(sprintf('Switch: %s (%s)', $switchConfig->name, $switchConfig->hostname));
        $this->info(sprintf('Connecting to %s:%d...', $switchConfig->hostname, $switchConfig->port ?? 22));

        $result = $tester->test($switchConfig);

        if (! $result->success) {
            $this->error(sprintf('Connection failed: %s', $result->exception?->getMessage()));

            return Command::FAILURE;
        }

        $this->info(sprintf('Connected. Found %d ports in %ss.', $result->portCount, $result->duration));

        return Command::SUCCESS;
    }
}
