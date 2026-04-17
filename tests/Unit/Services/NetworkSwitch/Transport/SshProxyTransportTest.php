<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch\Transport;

use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\Transport\SshProxyTransport;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\ProxyStatus;
use App\Services\SshProxy\SshProxyClientInterface;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SshProxyTransportTest extends TestCase
{
    public function test_execute_sends_commands_via_proxy_client(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'hostname' => 'switch.local',
            'username' => 'admin',
            'password' => 'password',
            'enable_password' => 'enable-pass',
        ]);

        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->with(
                'switch.local',
                'admin',
                'password',
                Mockery::on(function (array $commands): bool {
                    return $commands[0]['command'] === 'en'
                        && $commands[0]['if'] === '/>\s*$/'
                        && $commands[1]['command'] === 'enable-pass'
                        && $commands[1]['if'] === '/Password:/'
                        && $commands[2]['command'] === 'terminal length 0'
                        && $commands[3]['command'] === 'show interface status';
                }),
                22,
            )
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('en', ''),
                    new CommandOutput('enable-pass', ''),
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('show interface status', 'Gi1/0/1 connected'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame('Gi1/0/1 connected', $transport->execute('show interface status'));
    }

    public function test_execute_multiple_returns_outputs_keyed_by_command(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'hostname' => 'switch.local',
            'username' => 'admin',
            'password' => 'password',
            'enable_password' => 'enable-pass',
        ]);

        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('en', ''),
                    new CommandOutput('enable-pass', ''),
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('show interface status', 'status output'),
                    new CommandOutput('show mac address-table', 'fdb output'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $outputs = $transport->executeMultiple([
            'show interface status',
            'show mac address-table',
        ]);

        $this->assertSame([
            'show interface status' => 'status output',
            'show mac address-table' => 'fdb output',
        ], $outputs);
    }

    public function test_execute_skips_enable_commands_when_enable_password_is_missing(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'hostname' => 'switch.local',
            'username' => 'admin',
            'password' => 'password',
            'enable_password' => null,
        ]);

        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->with(
                'switch.local',
                'admin',
                'password',
                [
                    ['command' => 'terminal length 0', 'expect' => '/^.*[>#]$/'],
                    ['command' => 'show interface status', 'expect' => '/^.*[>#]$/'],
                ],
                22,
            )
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('show interface status', 'Gi1/0/1 connected'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame('Gi1/0/1 connected', $transport->execute('show interface status'));
    }

    public function test_enable_commands_have_if_conditions_for_pooled_connections(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'hostname' => 'switch.local',
            'username' => 'admin',
            'password' => 'password',
            'enable_password' => 'enable-pass',
        ]);

        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->with(
                'switch.local',
                'admin',
                'password',
                Mockery::on(function (array $commands): bool {
                    // en command must have 'if' to skip when already in enable mode
                    $this->assertSame('/>\s*$/', $commands[0]['if']);
                    $this->assertSame('/Password:/', $commands[0]['expect']);

                    // password command must have 'if' to skip when en was skipped
                    $this->assertSame('/Password:/', $commands[1]['if']);
                    $this->assertSame('/#\s*$/', $commands[1]['expect']);

                    // terminal length 0 must not have 'if' — always runs
                    $this->assertArrayNotHasKey('if', $commands[2]);

                    return true;
                }),
                22,
            )
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('show interface status', 'Gi1/0/1 connected'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame('Gi1/0/1 connected', $transport->execute('show interface status'));
    }

    public function test_execute_throws_when_proxy_output_is_missing_for_requested_command(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('en', ''),
                    new CommandOutput('enable123', ''),
                    new CommandOutput('terminal length 0', ''),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing output for switch command [show interface status].');

        $transport->execute('show interface status');
    }

    public function test_execute_throws_proxy_error_when_proxy_reports_failure(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: false,
                output: [],
                error: 'SSH connection refused',
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSH connection refused');

        $transport->execute('show interface status');
    }

    public function test_execute_multiple_throws_when_any_requested_output_is_missing(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('en', ''),
                    new CommandOutput('enable123', ''),
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('show interface status', 'status output'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing output for switch command [show mac address-table].');

        $transport->executeMultiple([
            'show interface status',
            'show mac address-table',
        ]);
    }

    public function test_is_connected_checks_proxy_health(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('status')
            ->once()
            ->andReturn(new ProxyStatus(3600, []));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertTrue($transport->isConnected());
    }
}
