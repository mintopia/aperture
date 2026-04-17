<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NetworkSwitch\Transport\DirectSshTransport;
use App\Services\NetworkSwitch\Transport\SshProxyTransport;
use App\Services\SshProxy\SshProxyClientInterface;
use InvalidArgumentException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class SwitchServiceFactoryTest extends TestCase
{
    public function test_make_creates_direct_transport_when_proxy_is_disabled(): void
    {
        $switchConfig = SwitchConfig::factory()->make([
            'hostname' => 'switch.local',
            'username' => 'db-admin',
            'password' => 'db-password',
            'enable_password' => 'db-enable',
            'port' => 2222,
            'timeout' => 15,
        ]);

        $factory = new SwitchServiceFactory(null, false);

        $service = $factory->make($switchConfig);

        $this->assertInstanceOf(NetworkSwitchInterface::class, $service);
        $this->assertInstanceOf(CiscoSwitchAdapter::class, $service);

        $transport = $this->extractTransport($service);
        $this->assertInstanceOf(DirectSshTransport::class, $transport);
        $this->assertSame('switch.local', $this->readProperty($transport, 'hostname'));
        $this->assertSame('db-admin', $this->readProperty($transport, 'username'));
        $this->assertSame('db-password', $this->readProperty($transport, 'password'));
        $this->assertSame('db-enable', $this->readProperty($transport, 'enablePassword'));
        $this->assertSame(2222, $this->readProperty($transport, 'port'));
        $this->assertSame(15, $this->readProperty($transport, 'timeout'));
    }

    public function test_make_creates_proxy_transport_when_proxy_is_enabled(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);

        $factory = new SwitchServiceFactory($proxyClient, true);

        $service = $factory->make($switchConfig);

        $this->assertInstanceOf(CiscoSwitchAdapter::class, $service);
        $this->assertInstanceOf(SshProxyTransport::class, $this->extractTransport($service));
    }

    public function test_make_normalizes_missing_enable_password_for_direct_transport(): void
    {
        $switchConfig = SwitchConfig::factory()->make(['enable_password' => null]);

        $factory = new SwitchServiceFactory(null, false);

        $transport = $this->extractTransport($factory->make($switchConfig));

        $this->assertSame('', $this->readProperty($transport, 'enablePassword'));
    }

    public function test_make_throws_for_unsupported_switch_type(): void
    {
        $factory = new SwitchServiceFactory(null, false);
        $switchConfig = SwitchConfig::factory()->make(['type' => 'juniper']);

        $this->expectException(InvalidArgumentException::class);

        $factory->make($switchConfig);
    }

    public function test_make_throws_when_proxy_is_enabled_without_proxy_client(): void
    {
        $factory = new SwitchServiceFactory(null, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSH proxy is enabled but no proxy client is available.');

        $factory->make(SwitchConfig::factory()->make());
    }

    private function extractTransport(CiscoSwitchAdapter $service): object
    {
        return $this->readProperty($service, 'transport');
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);

        return $prop->getValue($object);
    }
}
