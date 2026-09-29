<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class IpAddressDirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeIp(string $address, array $attrs = []): IpAddress
    {
        $ip = new IpAddress;
        $ip->address = $address;
        $ip->last_seen_at = now();
        foreach ($attrs as $k => $v) {
            $ip->{$k} = $v;
        }
        $ip->save();

        return $ip;
    }

    private function makeService(?CaptivePortalInterface $portal = null, ?RateLimitingInterface $limiter = null): IpAddressActionService
    {
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $macResolver->shouldReceive('resolveIpToMac')->andReturn(null);

        return new IpAddressActionService(
            $portal ?? $this->createStub(CaptivePortalInterface::class),
            $limiter ?? $this->createStub(RateLimitingInterface::class),
            $this->createStub(SwitchServiceFactory::class),
            $macResolver,
        );
    }

    public function test_enable_rate_limit_updates_firewall(): void
    {
        $limiter = Mockery::mock(RateLimitingInterface::class);
        $limiter->shouldReceive('limitIp')->once()->with('10.0.0.50');
        $limiter->shouldNotReceive('unlimitIp');

        $this->makeService(limiter: $limiter)->enableRateLimit($this->makeIp('10.0.0.50', ['rate_limit_enabled' => false]));
    }

    public function test_disable_rate_limit_updates_firewall(): void
    {
        $limiter = Mockery::mock(RateLimitingInterface::class);
        $limiter->shouldReceive('unlimitIp')->once()->with('10.0.0.51');
        $limiter->shouldNotReceive('limitIp');

        $this->makeService(limiter: $limiter)->disableRateLimit($this->makeIp('10.0.0.51', ['rate_limit_enabled' => true]));
    }

    public function test_enable_internet_updates_firewall(): void
    {
        $portal = Mockery::mock(CaptivePortalInterface::class);
        $portal->shouldReceive('addIp')->once()->with('10.0.0.52', Mockery::any());
        $portal->shouldNotReceive('removeIp');

        $this->makeService(portal: $portal)->enableInternet($this->makeIp('10.0.0.52', ['internet_enabled' => false]));
    }

    public function test_enable_internet_uses_user_nickname_as_description(): void
    {
        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('addIp')
            ->once()
            ->with('10.0.0.53', 'TestPlayer');

        $rateLimiter = Mockery::mock(RateLimitingInterface::class);

        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $macResolver->shouldReceive('resolveIpToMac')->andReturn(null);

        $factory = Mockery::mock(SwitchServiceFactory::class);

        $service = new IpAddressActionService($captivePortal, $rateLimiter, $factory, $macResolver);

        $ip = new IpAddress;
        $ip->address = '10.0.0.53';
        $ip->last_seen_at = now();
        $ip->internet_enabled = false;
        $ip->save();

        $user = User::factory()->create(['nickname' => 'TestPlayer']);
        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $service->enableInternet($ip);
    }

    public function test_disable_internet_updates_firewall(): void
    {
        $portal = Mockery::mock(CaptivePortalInterface::class);
        $portal->shouldReceive('removeIp')->once()->with('10.0.0.54');
        $portal->shouldNotReceive('addIp');

        $this->makeService(portal: $portal)->disableInternet($this->makeIp('10.0.0.54', ['internet_enabled' => true]));
    }
}
