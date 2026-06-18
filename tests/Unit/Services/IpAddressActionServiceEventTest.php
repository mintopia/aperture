<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Events\IpMacLinked;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IpAddressActionServiceEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  (CaptivePortalInterface&MockInterface)|null  $captivePortal
     * @param  (MacAddressResolverInterface&MockInterface)|null  $macResolver
     */
    private function createService(
        CaptivePortalInterface|MockInterface|null $captivePortal = null,
        MacAddressResolverInterface|MockInterface|null $macResolver = null,
    ): IpAddressActionService {
        /** @var CaptivePortalInterface $resolvedCaptivePortal */
        $resolvedCaptivePortal = $captivePortal ?? Mockery::mock(CaptivePortalInterface::class);
        /** @var RateLimitingInterface $rateLimiter */
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);
        /** @var SwitchServiceFactory $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        /** @var MacAddressResolverInterface $resolvedMacResolver */
        $resolvedMacResolver = $macResolver ?? Mockery::mock(MacAddressResolverInterface::class);

        return new IpAddressActionService(
            $resolvedCaptivePortal,
            $rateLimiter,
            $factory,
            $resolvedMacResolver,
        );
    }

    public function test_enable_internet_dispatches_ip_mac_linked_with_auth_source_and_process(): void
    {
        Event::fake([IpMacLinked::class]);

        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $captivePortal->shouldReceive('addIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn('aa:bb:cc:dd:ee:ff');

        $service = $this->createService(captivePortal: $captivePortal, macResolver: $macResolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $service->enableInternet($ip);

        Event::assertDispatched(IpMacLinked::class, fn (IpMacLinked $event): bool => $event->ip->is($ip)
            && $event->mac->mac_address === 'AA:BB:CC:DD:EE:FF'
            && $event->source === 'auth'
            && $event->process === 'auth');
    }

    public function test_enable_internet_dispatches_event_when_pivot_already_exists(): void
    {
        Event::fake([IpMacLinked::class]);

        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $captivePortal->shouldReceive('addIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn('aa:bb:cc:dd:ee:ff');

        $ip = IpAddress::factory()->create(['address' => '10.0.0.11']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip->macAddresses()->attach($mac, ['source' => 'auth', 'last_seen_at' => now()->subHour()]);

        $service = $this->createService(captivePortal: $captivePortal, macResolver: $macResolver);
        $service->enableInternet($ip);

        // Refreshing an existing link must still dispatch, so production rows
        // that were linked without a user association can heal.
        Event::assertDispatched(IpMacLinked::class, fn (IpMacLinked $event): bool => $event->ip->is($ip)
            && $event->mac->is($mac)
            && $event->source === 'auth'
            && $event->process === 'auth');
    }
}
