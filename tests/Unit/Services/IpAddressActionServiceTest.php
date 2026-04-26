<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IpAddressActionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  (CaptivePortalInterface&MockInterface)|null  $captivePortal
     * @param  (RateLimitingInterface&MockInterface)|null  $rateLimiter
     * @param  (SwitchServiceFactory&MockInterface)|null  $factory
     * @param  (MacAddressResolverInterface&MockInterface)|null  $macResolver
     */
    private function createService(
        CaptivePortalInterface|MockInterface|null $captivePortal = null,
        RateLimitingInterface|MockInterface|null $rateLimiter = null,
        SwitchServiceFactory|MockInterface|null $factory = null,
        MacAddressResolverInterface|MockInterface|null $macResolver = null,
    ): IpAddressActionService {
        /** @var CaptivePortalInterface $resolvedCaptivePortal */
        $resolvedCaptivePortal = $captivePortal ?? Mockery::mock(CaptivePortalInterface::class);
        /** @var RateLimitingInterface $resolvedRateLimiter */
        $resolvedRateLimiter = $rateLimiter ?? Mockery::mock(RateLimitingInterface::class);
        /** @var SwitchServiceFactory $resolvedFactory */
        $resolvedFactory = $factory ?? Mockery::mock(SwitchServiceFactory::class);
        /** @var MacAddressResolverInterface $resolvedMacResolver */
        $resolvedMacResolver = $macResolver ?? Mockery::mock(MacAddressResolverInterface::class);

        return new IpAddressActionService(
            $resolvedCaptivePortal,
            $resolvedRateLimiter,
            $resolvedFactory,
            $resolvedMacResolver,
        );
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(IpAddressActionService::class));
    }

    public function test_enable_internet_calls_captive_portal_add_ip(): void
    {
        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $captivePortal->shouldReceive('addIp')->once()->with('10.0.0.1', Mockery::any());
        $macResolver->shouldReceive('resolveIpToMac')->once()->with('10.0.0.1')->andReturn(null);

        $service = $this->createService(captivePortal: $captivePortal, macResolver: $macResolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1', 'comment' => 'test']);
        $service->enableInternet($ip);
    }

    public function test_enable_internet_links_mac_address_when_resolved(): void
    {
        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $captivePortal->shouldReceive('addIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn('aa:bb:cc:dd:ee:ff');

        $service = $this->createService(captivePortal: $captivePortal, macResolver: $macResolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.2']);
        $service->enableInternet($ip);

        $ip->refresh();
        $this->assertNotNull($ip->currentMac());

        // NormalizeMacAddress cast converts to uppercase colon-separated format
        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->first();
        $this->assertNotNull($mac);
    }

    public function test_enable_internet_does_not_save_ip_fields(): void
    {
        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $captivePortal->shouldReceive('addIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn(null);

        $service = $this->createService(captivePortal: $captivePortal, macResolver: $macResolver);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.3',
            'internet_enabled' => false,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->enableInternet($ip);

        $ip->refresh();
        // internet_enabled should NOT be changed by the service — the observer handles it
        $this->assertFalse($ip->internet_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_disable_internet_calls_captive_portal_remove_ip(): void
    {
        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);

        $captivePortal->shouldReceive('removeIp')->once()->with('10.0.0.4');

        $service = $this->createService(captivePortal: $captivePortal);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.4']);
        $service->disableInternet($ip);
    }

    public function test_disable_internet_does_not_save_ip_fields(): void
    {
        /** @var CaptivePortalInterface&MockInterface $captivePortal */
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);

        $captivePortal->shouldReceive('removeIp')->once();

        $service = $this->createService(captivePortal: $captivePortal);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.5',
            'internet_enabled' => true,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->disableInternet($ip);

        $ip->refresh();
        // internet_enabled should NOT be changed by the service
        $this->assertTrue($ip->internet_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_enable_rate_limit_calls_rate_limiter_limit_ip(): void
    {
        /** @var RateLimitingInterface&MockInterface $rateLimiter */
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);

        $rateLimiter->shouldReceive('limitIp')->once()->with('10.0.0.6');

        $service = $this->createService(rateLimiter: $rateLimiter);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.6']);
        $service->enableRateLimit($ip);
    }

    public function test_enable_rate_limit_does_not_save_ip_fields(): void
    {
        /** @var RateLimitingInterface&MockInterface $rateLimiter */
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);

        $rateLimiter->shouldReceive('limitIp')->once();

        $service = $this->createService(rateLimiter: $rateLimiter);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.7',
            'rate_limit_enabled' => false,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->enableRateLimit($ip);

        $ip->refresh();
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_disable_rate_limit_calls_rate_limiter_unlimit_ip(): void
    {
        /** @var RateLimitingInterface&MockInterface $rateLimiter */
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);

        $rateLimiter->shouldReceive('unlimitIp')->once()->with('10.0.0.8');

        $service = $this->createService(rateLimiter: $rateLimiter);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.8']);
        $service->disableRateLimit($ip);
    }

    public function test_disable_rate_limit_does_not_save_ip_fields(): void
    {
        /** @var RateLimitingInterface&MockInterface $rateLimiter */
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);

        $rateLimiter->shouldReceive('unlimitIp')->once();

        $service = $this->createService(rateLimiter: $rateLimiter);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.9',
            'rate_limit_enabled' => true,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->disableRateLimit($ip);

        $ip->refresh();
        $this->assertTrue($ip->rate_limit_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }
}
