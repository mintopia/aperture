<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TestSwitchConnectionCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('aperture:test-switch-connection')
            ->assertExitCode(0);
    }

    public function test_command_succeeds_with_valid_switch_id(): void
    {
        $switchConfig = SwitchConfig::factory()->create([
            'name' => 'Lab Switch',
            'hostname' => 'sw-lab.example.com',
            'port' => 22,
        ]);

        $ports = new Collection(array_map(
            fn (int $i): PortStatus => new PortStatus(
                interface: sprintf('Gi1/0/%d', $i),
                status: 'connected',
                speed: '1000',
            ),
            range(1, 48),
        ));

        $adapter = Mockery::mock(NetworkSwitchInterface::class);
        $adapter->shouldReceive('getAllPorts')
            ->once()
            ->andReturn($ports);

        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->id === $switchConfig->id))
            ->once()
            ->andReturn($adapter);

        $this->app->instance(SwitchServiceFactory::class, $factory);

        $this->artisan('aperture:test-switch-connection', ['switch' => $switchConfig->id])
            ->expectsOutputToContain('Lab Switch')
            ->expectsOutputToContain('sw-lab.example.com')
            ->expectsOutputToContain('Found 48 ports')
            ->assertExitCode(0);
    }

    public function test_command_succeeds_with_hostname(): void
    {
        $switchConfig = SwitchConfig::factory()->create([
            'name' => 'Lab Switch',
            'hostname' => 'sw-lab.example.com',
            'port' => 22,
        ]);

        $ports = new Collection(array_map(
            fn (int $i): PortStatus => new PortStatus(
                interface: sprintf('Gi1/0/%d', $i),
                status: 'connected',
                speed: '1000',
            ),
            range(1, 24),
        ));

        $adapter = Mockery::mock(NetworkSwitchInterface::class);
        $adapter->shouldReceive('getAllPorts')
            ->once()
            ->andReturn($ports);

        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->id === $switchConfig->id))
            ->once()
            ->andReturn($adapter);

        $this->app->instance(SwitchServiceFactory::class, $factory);

        $this->artisan('aperture:test-switch-connection', ['switch' => 'sw-lab.example.com'])
            ->expectsOutputToContain('Found 24 ports')
            ->assertExitCode(0);
    }

    public function test_command_fails_with_unknown_switch(): void
    {
        $this->artisan('aperture:test-switch-connection', ['switch' => '9999'])
            ->expectsOutputToContain('Switch config not found')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_connection_errors(): void
    {
        $switchConfig = SwitchConfig::factory()->create([
            'name' => 'Bad Switch',
            'hostname' => 'sw-bad.example.com',
        ]);

        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->app->instance(SwitchServiceFactory::class, $factory);

        $this->artisan('aperture:test-switch-connection', ['switch' => $switchConfig->id])
            ->expectsOutputToContain('Connection failed: Connection refused')
            ->assertExitCode(1);
    }
}
