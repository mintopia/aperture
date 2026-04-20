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
                    ['command' => 'terminal length 0', 'expect' => '/^.*[>#]\s*$/'],
                    ['command' => 'show interface status', 'expect' => '/^.*[>#]\s*$/'],
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

    public function test_execute_builds_enable_sequence_with_privileged_prompt_after_enable_password(): void
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
                    $this->assertSame([
                        ['command' => 'en', 'if' => '/>\s*$/', 'expect' => '/Password:/'],
                        ['command' => 'enable-pass', 'if' => '/Password:/', 'expect' => '/^.*#\s*$/'],
                        ['command' => 'terminal length 0', 'expect' => '/^.*#\s*$/'],
                        ['command' => 'show interface status', 'expect' => '/^.*#\s*$/'],
                    ], $commands);

                    return true;
                }),
                22,
            )
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput('en', ''),
                    new CommandOutput('enable-pass', ''),
                    new CommandOutput('show interface status', 'Gi1/0/1 connected'),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame('Gi1/0/1 connected', $transport->execute('show interface status'));
    }

    public function test_enable_sequence_keeps_if_conditions_and_uses_privileged_prompts_for_pooled_connections(): void
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
                    $this->assertSame('/^.*#\s*$/', $commands[1]['expect']);

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

    public function test_execute_uses_prompt_regex_that_tolerates_trailing_whitespace_without_enable_password(): void
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
                Mockery::on(function (array $commands): bool {
                    foreach ($commands as $command) {
                        $expectPattern = $command['expect'];

                        $this->assertSame(
                            1,
                            preg_match($expectPattern, 'switch#   '),
                            "Expected [{$expectPattern}] to match prompt with trailing spaces.",
                        );
                        $this->assertSame(
                            1,
                            preg_match($expectPattern, "switch>\t\n"),
                            "Expected [{$expectPattern}] to match prompt with trailing whitespace/newline.",
                        );
                    }

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

    public function test_execute_uses_privileged_prompt_regex_after_enable_password(): void
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
                    $postEnablePromptRegex = $commands[1]['expect'];
                    $commandPromptRegex = $commands[2]['expect'];

                    $this->assertSame(
                        1,
                        preg_match($postEnablePromptRegex, 'switch#   '),
                        "Expected [{$postEnablePromptRegex}] to match prompt with trailing spaces.",
                    );
                    $this->assertSame(
                        1,
                        preg_match($postEnablePromptRegex, "switch# \n"),
                        "Expected [{$postEnablePromptRegex}] to match prompt with trailing whitespace/newline.",
                    );

                    $this->assertSame(
                        1,
                        preg_match($commandPromptRegex, 'switch#   '),
                        "Expected [{$commandPromptRegex}] to match prompt with trailing spaces.",
                    );
                    $this->assertSame(
                        1,
                        preg_match($commandPromptRegex, "switch#\t\n"),
                        "Expected [{$commandPromptRegex}] to match prompt with trailing whitespace/newline.",
                    );
                    $this->assertSame(0, preg_match($commandPromptRegex, "switch>\t\n"));

                    return true;
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

    public function test_execute_requires_privileged_prompt_expectations_after_enable_password(): void
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
                    $privilegedPromptRegex = '/^.*#\s*$/';

                    // Once enable is requested, prompt expectations must require privileged mode (#).
                    $this->assertSame($privilegedPromptRegex, $commands[1]['expect']);
                    $this->assertSame($privilegedPromptRegex, $commands[2]['expect']);
                    $this->assertSame($privilegedPromptRegex, $commands[3]['expect']);

                    // Optional trailing whitespace should be allowed.
                    $this->assertSame(1, preg_match($commands[1]['expect'], "switch# \n"));
                    $this->assertSame(1, preg_match($commands[2]['expect'], "switch#\t"));
                    $this->assertSame(1, preg_match($commands[3]['expect'], 'switch#   '));

                    // Non-privileged prompt must not satisfy post-enable expectations.
                    $this->assertSame(0, preg_match($commands[1]['expect'], 'switch>'));
                    $this->assertSame(0, preg_match($commands[2]['expect'], 'switch>   '));
                    $this->assertSame(0, preg_match($commands[3]['expect'], "switch>\n"));

                    return true;
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

    public function test_execute_strips_command_echo_and_trailing_hash_prompt_from_show_run_interface_output(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput(
                        'show run interface Gi1/0/1',
                        "show run interface Gi1/0/1\r\n".
                        "interface Gi1/0/1\r\n".
                        " description Uplink\r\n".
                        " switchport mode trunk\r\n".
                        "!\r\n".
                        'switch01#'
                    ),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame(
            "interface Gi1/0/1\r\n description Uplink\r\n switchport mode trunk\r\n!",
            $transport->execute('show run interface Gi1/0/1')
        );
    }

    public function test_execute_strips_command_echo_and_trailing_angle_prompt_from_show_interface_output(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'enable_password' => null,
        ]);
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $proxyClient->shouldReceive('execute')
            ->once()
            ->andReturn(new CommandResult(
                success: true,
                output: [
                    new CommandOutput('terminal length 0', ''),
                    new CommandOutput(
                        'show interface Gi1/0/1',
                        "show interface Gi1/0/1\r\n".
                        "GigabitEthernet1/0/1 is up, line protocol is up\r\n".
                        "  MTU 1500 bytes, BW 1000000 Kbit/sec\r\n".
                        'switch01>'
                    ),
                ],
            ));

        $transport = new SshProxyTransport($proxyClient, $switchConfig);

        $this->assertSame(
            "GigabitEthernet1/0/1 is up, line protocol is up\r\n  MTU 1500 bytes, BW 1000000 Kbit/sec",
            $transport->execute('show interface Gi1/0/1')
        );
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
