<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NetworkSwitch\Transport\SshProxyTransport;
use InvalidArgumentException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class SwitchServiceFactoryTest extends TestCase
{
    public function test_make_creates_proxy_transport_when_proxy_client_is_present(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);

        $factory = new SwitchServiceFactory($proxyClient);

        $service = $factory->make($switchConfig);

        $this->assertInstanceOf(NetworkSwitchInterface::class, $service);
        $this->assertInstanceOf(CiscoSwitchAdapter::class, $service);
        $this->assertInstanceOf(SshProxyTransport::class, $this->extractTransport($service));
    }

    public function test_create_transport_returns_proxy_transport(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);

        $factory = new SwitchServiceFactory($proxyClient);

        $this->assertInstanceOf(SshProxyTransport::class, $factory->createTransport($switchConfig));
    }

    public function test_make_throws_when_proxy_client_is_missing(): void
    {
        $factory = new SwitchServiceFactory(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSH proxy client is not available. The SSH proxy is the only supported switch transport; configure aperture.ssh_proxy and run the ssh-proxy sidecar.');

        $factory->make(SwitchConfig::factory()->make());
    }

    public function test_create_transport_throws_when_proxy_client_is_missing(): void
    {
        $factory = new SwitchServiceFactory(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSH proxy client is not available. The SSH proxy is the only supported switch transport; configure aperture.ssh_proxy and run the ssh-proxy sidecar.');

        $factory->createTransport(SwitchConfig::factory()->make());
    }

    public function test_make_throws_for_unsupported_switch_type(): void
    {
        $proxyClient = Mockery::mock(SshProxyClientInterface::class);
        $factory = new SwitchServiceFactory($proxyClient);
        $switchConfig = SwitchConfig::factory()->make(['type' => 'juniper']);

        $this->expectException(InvalidArgumentException::class);

        $factory->make($switchConfig);
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
