<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SwitchPortActionJob;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SwitchPortActionJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createSwitchAndPort(string $adminStatus = 'up'): array
    {
        $switch = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'admin_status' => $adminStatus,
        ]);

        return [$switch, $port];
    }

    private function mockFactory(SwitchConfig $switch, string $expectedMethod): SwitchServiceFactory
    {
        /** @var MockInterface&NetworkSwitchInterface $adapter */
        $adapter = Mockery::mock(NetworkSwitchInterface::class);
        $adapter->shouldReceive($expectedMethod)
            ->with('Gi0/1')
            ->once()
            ->andReturn(true);

        /** @var MockInterface&SwitchServiceFactory $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switch)))
            ->once()
            ->andReturn($adapter);

        return $factory;
    }

    public function test_shutdown_action_calls_adapter_and_updates_db(): void
    {
        [$switch, $port] = $this->createSwitchAndPort('up');
        $factory = $this->mockFactory($switch, 'shutdownPort');

        $job = new SwitchPortActionJob($switch, 'Gi0/1', 'shutdown');
        $job->handle($factory);

        $this->assertEquals('down', $port->fresh()->admin_status);
    }

    public function test_enable_action_calls_adapter_and_updates_db(): void
    {
        [$switch, $port] = $this->createSwitchAndPort('down');
        $factory = $this->mockFactory($switch, 'enablePort');

        $job = new SwitchPortActionJob($switch, 'Gi0/1', 'enable');
        $job->handle($factory);

        $this->assertEquals('up', $port->fresh()->admin_status);
    }

    public function test_invalid_action_is_rejected(): void
    {
        [$switch] = $this->createSwitchAndPort();

        Log::shouldReceive('error')
            ->once()
            ->with('Invalid SwitchPortActionJob action', ['action' => 'reboot']);

        /** @var MockInterface&SwitchServiceFactory $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldNotReceive('make');

        $job = new SwitchPortActionJob($switch, 'Gi0/1', 'reboot');
        $job->handle($factory);
    }
}
