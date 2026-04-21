<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch\Transport;

use App\Services\NetworkSwitch\Transport\DirectSshTransport;
use Exception;
use Mockery;
use phpseclib3\Net\SSH2;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class DirectSshTransportTest extends TestCase
{
    public function test_execute_runs_command_over_ssh(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->once()->with(10)->andReturnNull();
        $ssh->shouldReceive('read')->once()->withNoArgs()->andReturn("Switch>\n");
        $ssh->shouldReceive('write')->once()->with("terminal length 0\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch>')->andReturn('Switch>');
        $ssh->shouldReceive('write')->once()->with("en\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Password:')->andReturn('Password:');
        $ssh->shouldReceive('write')->once()->with("enable-pass\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch#')->andReturn('Switch#');
        $ssh->shouldReceive('write')->once()->with("show interface status\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with("Switch#\n")->andReturn("show interface status\r\nGi1/0/1 connected\r\nSwitch#");

        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                parent::__construct('switch.local', 'admin', 'password', 'enable-pass', 2222, 10);
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }
        };

        $result = $transport->execute('show interface status');

        $this->assertSame('Gi1/0/1 connected', $result);
        $this->assertTrue($transport->isConnected());
    }

    public function test_execute_multiple_runs_commands_sequentially(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->once()->with(10)->andReturnNull();
        $ssh->shouldReceive('read')->once()->withNoArgs()->andReturn("Switch>\n");
        $ssh->shouldReceive('write')->once()->with("terminal length 0\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch>')->andReturn('Switch>');
        $ssh->shouldReceive('write')->once()->with("en\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Password:')->andReturn('Password:');
        $ssh->shouldReceive('write')->once()->with("enable-pass\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch#')->andReturn('Switch#');
        $ssh->shouldReceive('write')->once()->with("configure terminal\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('(config)#')->andReturn("configure terminal\r\nSwitch(config)#");
        $ssh->shouldReceive('write')->once()->with("interface Gi1/0/1\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('(config-if)#')->andReturn("interface Gi1/0/1\r\nSwitch(config-if)#");
        $ssh->shouldReceive('write')->once()->with("shutdown\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('(config-if)#')->andReturn("shutdown\r\nSwitch(config-if)#");
        $ssh->shouldReceive('write')->once()->with("end\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch#')->andReturn("end\r\nSwitch#");
        $ssh->shouldReceive('write')->once()->with("write memory\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch#')->andReturn("write memory\r\n[OK]\r\nSwitch#");

        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                parent::__construct('switch.local', 'admin', 'password', 'enable-pass', 22, 10);
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }
        };

        $result = $transport->executeMultiple([
            'configure terminal',
            'interface Gi1/0/1',
            'shutdown',
            'end',
            'write memory',
        ]);

        $this->assertSame('[OK]', $result['write memory']);
    }

    public function test_disconnect_resets_connection_state(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('disconnect')->once()->andReturnNull();
        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                parent::__construct('switch.local', 'admin', 'password', 'enable-pass');
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }

            public function setConnectedState(): void
            {
                $reflection = new ReflectionClass($this);
                $parent = $reflection->getParentClass();
                $connection = $parent->getProperty('connection');
                $connection->setValue($this, $this->sshMock);

                $enabled = $parent->getProperty('enableMode');
                $enabled->setValue($this, true);

                $prompt = $parent->getProperty('prompt');
                $prompt->setValue($this, 'Switch#');
            }
        };

        $transport->setConnectedState();
        $transport->disconnect();

        $this->assertFalse($transport->isConnected());
    }

    public function test_authentication_failure_does_not_leave_transport_marked_connected(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('admin', 'password')->andReturn(false);

        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                parent::__construct('switch.local', 'admin', 'password', '');
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }
        };

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unable to authenticate with switch');

        try {
            $transport->execute('show interface status');
        } finally {
            $this->assertFalse($transport->isConnected());
        }
    }

    public function test_read_timeout_throws_runtime_exception(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->once()->with(5)->andReturnNull();
        $ssh->shouldReceive('read')->once()->withNoArgs()->andReturn(false);

        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                parent::__construct('switch.local', 'admin', 'password', '');
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSH read timed out waiting for [initial prompt] on switch.local');

        $transport->execute('show interface status');
    }

    public function test_enter_enable_mode_returns_early_when_already_in_enable_mode(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->once()->with(5)->andReturnNull();
        $ssh->shouldReceive('read')->once()->withNoArgs()->andReturn("Switch>\n");
        $ssh->shouldReceive('write')->once()->with("terminal length 0\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with('Switch>')->andReturn('Switch>');

        // en/enable password read/writes should NOT happen because enableMode is already true
        $ssh->shouldNotReceive('write')->with("en\n");

        // Once we are already in enable mode on connect, calling a command should use the # prompt
        $ssh->shouldReceive('write')->once()->with("show version\n")->andReturn(true);
        $ssh->shouldReceive('read')->once()->with("Switch>\n")->andReturn("show version\r\nCisco IOS Version\r\nSwitch>");

        $transport = new class($ssh) extends DirectSshTransport
        {
            public function __construct(private readonly SSH2 $sshMock)
            {
                // Pass empty enable password so enterEnableMode returns early on first connect
                parent::__construct('switch.local', 'admin', 'password', '');
            }

            protected function createSshConnection(): SSH2
            {
                return $this->sshMock;
            }
        };

        // First call connects and enters non-enable mode (empty enable password)
        $transport->execute('show version');

        // Mark as already in enable mode via reflection
        $reflection = new ReflectionClass($transport);
        $parent = $reflection->getParentClass();
        $enableMode = $parent->getProperty('enableMode');
        $enableMode->setValue($transport, true);

        // enterEnableMode should now hit the early-return (line 105) path
        $enterEnableMode = $parent->getMethod('enterEnableMode');
        $enterEnableMode->invoke($transport); // Should not throw or call SSH write

        $this->assertTrue(true);
    }

    public function test_create_ssh_connection_returns_ssh2_instance(): void
    {
        // Test the real createSshConnection method by using a transport that does NOT override it
        // We verify it returns an SSH2 instance by calling it via reflection (no actual network call)
        $transport = new class extends DirectSshTransport
        {
            public function __construct()
            {
                parent::__construct('192.0.2.1', 'user', 'pass', '', 22, 1);
            }

            public function exposeCreateSshConnection(): SSH2
            {
                return $this->createSshConnection();
            }
        };

        $ssh = $transport->exposeCreateSshConnection();

        $this->assertInstanceOf(SSH2::class, $ssh);
    }
}
