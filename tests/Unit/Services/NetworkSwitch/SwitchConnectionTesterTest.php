<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\SwitchConnectionTester;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SwitchConnectionTesterTest extends TestCase
{
    public function test_runs_through_proxy_transport_using_the_configured_ssh_port(): void
    {
        $switch = SwitchConfig::factory()->make(['hostname' => 'sw.local', 'port' => 2222, 'enable_password' => null]);

        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')
            ->once()
            ->with('sw.local', $switch->username, Mockery::any(), Mockery::type('array'), 2222, 'commands', null, null, null)
            ->andReturn(new CommandResult(true, [
                new CommandOutput('terminal length 0', ''),
                new CommandOutput('show interface status', ''),
            ]));

        $result = (new SwitchConnectionTester(new SwitchServiceFactory($proxy)))->test($switch);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->portCount);
    }

    public function test_failure_carries_the_exception(): void
    {
        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')->once()->andReturn(new CommandResult(false, [], 'refused'));

        $result = (new SwitchConnectionTester(new SwitchServiceFactory($proxy)))->test(SwitchConfig::factory()->make());

        $this->assertFalse($result->success);
        $this->assertInstanceOf(RuntimeException::class, $result->exception);
        $this->assertSame('refused', $result->exception->getMessage());
    }
}
