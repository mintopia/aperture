<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Console\Command;
use Throwable;

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

    public function handle(SwitchServiceFactory $factory): int
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

        try {
            $startTime = microtime(true);
            $adapter = $factory->make($switchConfig);
            $ports = $adapter->getAllPorts();
            $duration = round(microtime(true) - $startTime, 3);

            $this->info(sprintf('Connected. Found %d ports in %ss.', $ports->count(), $duration));

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error(sprintf('Connection failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }
    }
}
