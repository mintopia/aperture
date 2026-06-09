<?php

namespace Tests\Unit\Models;

use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpAddressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_users_returns_has_many_relationship(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->assertInstanceOf(HasMany::class, $ip->users());
    }

    public function test_to_string_returns_address(): void
    {
        $ip = new IpAddress;
        $ip->address = '192.168.1.1';
        $this->assertStringContainsString('192.168.1.1', (string) $ip);
        $this->assertStringContainsString('[IpAddress:', (string) $ip);
    }

    public function test_route_key_name_is_address(): void
    {
        $ip = new IpAddress;
        $this->assertSame('address', $ip->getRouteKeyName());
    }

    public function test_current_mac_returns_null_when_no_mac_addresses_attached(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertNull($ip->currentMac());
    }

    public function test_current_mac_returns_mac_when_attached_via_pivot(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'auth', 'last_seen_at' => now()]);

        $this->assertSame('AA:BB:CC:DD:EE:FF', $ip->currentMac()?->mac_address);
    }

    public function test_mac_addresses_returns_belongs_to_many_relationship(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertInstanceOf(BelongsToMany::class, $ip->macAddresses());
    }

    public function test_get_falls_back_to_parent_for_other_attributes(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->assertEquals('10.0.0.1', $ip->address);
    }

    public function test_ipv6_address_is_normalized_to_lowercase(): void
    {
        $ip = IpAddress::create([
            'address' => '2001:DB8::ABCD:1',
            'last_seen_at' => now(),
        ]);

        $this->assertEquals('2001:db8::abcd:1', $ip->address);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::abcd:1']);
    }

    public function test_ipv4_address_is_not_affected(): void
    {
        $ip = IpAddress::create([
            'address' => '10.30.0.1',
            'last_seen_at' => now(),
        ]);

        $this->assertEquals('10.30.0.1', $ip->address);
    }

    public function test_normalize_lowercases_ipv6_addresses(): void
    {
        $this->assertSame('2001:db8::abcd:1', IpAddress::normalize('2001:DB8::ABCD:1'));
    }

    public function test_normalize_leaves_ipv4_addresses_unchanged(): void
    {
        $this->assertSame('10.30.0.1', IpAddress::normalize('10.30.0.1'));
    }

    public function test_enabling_rate_limit_dispatches_sync_rate_limit_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->rate_limit_enabled = true;
        $ip->save();
        Queue::assertPushed(SyncRateLimitJob::class);
    }

    public function test_disabling_rate_limit_dispatches_sync_rate_limit_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->rate_limit_enabled = true;
        $ip->save();

        $ip->rate_limit_enabled = false;
        $ip->save();
        Queue::assertPushed(SyncRateLimitJob::class);
    }

    public function test_enabling_internet_dispatches_sync_internet_access_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->internet_enabled = true;
        $ip->save();
        Queue::assertPushed(SyncInternetAccessJob::class);
    }

    public function test_disabling_internet_dispatches_sync_internet_access_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->save();

        $ip->internet_enabled = false;
        $ip->save();
        Queue::assertPushed(SyncInternetAccessJob::class);
    }
}
