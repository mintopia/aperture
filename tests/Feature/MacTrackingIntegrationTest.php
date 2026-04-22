<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MacTrackingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock firewall to prevent real HTTP calls
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $firewall);
    }

    private function makeService(): IpAddressActionService
    {
        return app(IpAddressActionService::class);
    }

    public function test_allow_links_mac_when_resolved(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.100')
            ->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.100']);
        $this->makeService()->enableInternet($ip);

        $ip->refresh();
        $this->assertNotNull($ip->mac_address_id);
        $this->assertDatabaseHas('mac_addresses', [
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'source' => 'auth',
            'allowed' => true,
        ]);
    }

    public function test_allow_continues_when_mac_not_resolved(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.101']);
        $this->makeService()->enableInternet($ip);

        $ip->refresh();
        $this->assertNull($ip->mac_address_id);
    }

    public function test_allow_reuses_existing_mac_address_record(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $existingMac = MacAddress::factory()->allowed()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.102']);
        $this->makeService()->enableInternet($ip);

        $ip->refresh();
        $this->assertSame($existingMac->id, $ip->mac_address_id);
        $this->assertSame(1, MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->count());
    }

    public function test_allow_associates_user_with_mac(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $user = User::factory()->create(['nickname' => 'TestPlayer']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.103']);

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->makeService()->enableInternet($ip);

        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->first();
        $this->assertSame($user->id, $mac->user_id);
    }

    public function test_allow_survives_mac_resolution_failure(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andThrow(new RuntimeException('Service down'));
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.104']);
        $this->makeService()->enableInternet($ip);

        $ip->refresh();
        $this->assertNull($ip->mac_address_id);
    }

    public function test_allow_marks_existing_unallowed_mac_as_allowed(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturn('BB:CC:DD:EE:FF:00');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $mac = MacAddress::factory()->create([
            'mac_address' => 'BB:CC:DD:EE:FF:00',
            'allowed' => false,
            'allowed_at' => null,
        ]);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.105']);
        $this->makeService()->enableInternet($ip);

        $mac->refresh();
        $this->assertTrue((bool) $mac->allowed);
        $this->assertNotNull($mac->allowed_at);
    }
}
