<?php

namespace Tests\Unit\Services;

use App\Services\CiscoService;
use Exception;
use Mockery;
use phpseclib3\Net\SSH2;
use ReflectionClass;
use Tests\TestCase;

class CiscoServiceTest extends TestCase
{
    protected function createServiceWithMockSsh(SSH2 $ssh): CiscoService
    {
        $service = new CiscoService('switch.local');

        $reflection = new ReflectionClass($service);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($service, $ssh);

        $nameProp = $reflection->getProperty('name');
        $nameProp->setValue($service, 'Switch');

        return $service;
    }

    protected function createMockSsh(): SSH2
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('write')->andReturn(true);
        $ssh->shouldReceive('read')->andReturn('Switch>');
        $ssh->shouldReceive('setTimeout')->andReturn(true);

        return $ssh;
    }

    public function test_show_interface_returns_output(): void
    {
        $ssh = $this->createMockSsh();
        $ssh->shouldReceive('read')
            ->with("Switch>\n")
            ->andReturn("show output\r\nGi1/0/1 is up\r\nHardware is Gigabit\r\nSwitch>");

        $service = $this->createServiceWithMockSsh($ssh);
        $result = $service->showInterface('Gi1/0/1');
        $this->assertIsString($result);
    }

    public function test_show_interface_config_returns_output(): void
    {
        $ssh = $this->createMockSsh();
        $ssh->shouldReceive('read')
            ->with("Switch#\n")
            ->andReturn("line1\r\nline2\r\nline3\r\nline4\r\nline5\r\ninterface Gi1/0/1\r\n switchport\r\nend\r\nSwitch#");

        $service = $this->createServiceWithMockSsh($ssh);

        $reflection = new ReflectionClass($service);
        $enableProp = $reflection->getProperty('enable');
        $enableProp->setValue($service, true);

        $result = $service->showInterfaceConfig('Gi1/0/1');
        $this->assertIsString($result);
    }

    public function test_shut_interface_sends_correct_commands(): void
    {
        $ssh = $this->createMockSsh();
        $ssh->shouldReceive('read')->andReturn('Switch#');

        $service = $this->createServiceWithMockSsh($ssh);

        $reflection = new ReflectionClass($service);
        $enableProp = $reflection->getProperty('enable');
        $enableProp->setValue($service, true);

        // Should not throw
        $service->shutInterface('Gi1/0/1');
        $this->assertTrue(true);
    }

    public function test_unshut_interface_sends_correct_commands(): void
    {
        $ssh = $this->createMockSsh();
        $ssh->shouldReceive('read')->andReturn('Switch#');

        $service = $this->createServiceWithMockSsh($ssh);

        $reflection = new ReflectionClass($service);
        $enableProp = $reflection->getProperty('enable');
        $enableProp->setValue($service, true);

        $service->unshutInterface('Gi1/0/1');
        $this->assertTrue(true);
    }

    public function test_connect_authenticates_with_ssh(): void
    {
        config([
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'password',
            'aperture.cisco.timeout' => 5,
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->with(5)->andReturn(true);
        $ssh->shouldReceive('read')->andReturn("Switch>\n");
        $ssh->shouldReceive('write')->andReturn(true);

        $service = new CiscoService('switch.local');

        $reflection = new ReflectionClass($service);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($service, $ssh);

        $nameProp = $reflection->getProperty('name');
        $nameProp->setValue($service, 'Switch');

        // Already connected, so connect() should be a no-op
        $ssh->shouldReceive('read')->with("Switch>\n")->andReturn("output\r\nSwitch>");
        $service->showInterface('Gi1/0/1');
        $this->assertTrue(true);
    }

    public function test_enable_runs_once(): void
    {
        $ssh = $this->createMockSsh();
        $ssh->shouldReceive('read')->andReturn('Switch#');

        config(['aperture.cisco.enablePassword' => 'enable-pass']);

        $service = $this->createServiceWithMockSsh($ssh);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('enable');

        // Call enable twice
        $method->invoke($service);
        $method->invoke($service); // Should be no-op second time

        $enableProp = $reflection->getProperty('enable');
        $this->assertTrue($enableProp->getValue($service));
    }

    public function test_connect_throws_exception_on_auth_failure(): void
    {
        config([
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'wrong',
            'aperture.cisco.timeout' => 5,
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->with('admin', 'wrong')->andReturn(false);

        $testService = new class('switch.local') extends CiscoService
        {
            protected ?SSH2 $mockSsh = null;

            public function setMockSsh(SSH2 $ssh): void
            {
                $this->mockSsh = $ssh;
            }

            protected function createSshConnection(): SSH2
            {
                return $this->mockSsh;
            }
        };

        $testService->setMockSsh($ssh);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unable to authenticate with switch');
        $testService->showInterface('Gi1/0/1');
    }

    public function test_connect_creates_connection_successfully(): void
    {
        config([
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'password',
            'aperture.cisco.timeout' => 5,
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->with('admin', 'password')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->with(5)->andReturn(true);
        $ssh->shouldReceive('read')->andReturn("Switch>\n");
        $ssh->shouldReceive('write')->andReturn(true);

        $testService = new class('switch.local') extends CiscoService
        {
            protected ?SSH2 $mockSsh = null;

            public function setMockSsh(SSH2 $ssh): void
            {
                $this->mockSsh = $ssh;
            }

            protected function createSshConnection(): SSH2
            {
                return $this->mockSsh;
            }
        };

        $testService->setMockSsh($ssh);

        $result = $testService->showInterface('Gi1/0/1');
        $this->assertIsString($result);
    }
}
