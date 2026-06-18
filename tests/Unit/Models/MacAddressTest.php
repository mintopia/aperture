<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MacAddressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_mac_address(): void
    {
        $mac = MacAddress::factory()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $this->assertDatabaseHas('mac_addresses', [
            'id' => $mac->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF', // normalized
        ]);
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($mac->user->is($user));
    }

    public function test_user_is_nullable(): void
    {
        $mac = MacAddress::factory()->create(['user_id' => null]);

        $this->assertNull($mac->user);
    }

    public function test_belongs_to_many_ip_addresses(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        $mac->ipAddresses()->attach($ip, ['source' => 'auth', 'last_seen_at' => now()]);

        $this->assertInstanceOf(BelongsToMany::class, $mac->ipAddresses());
        $this->assertTrue($mac->ipAddresses->contains($ip));
    }

    public function test_user_has_many_mac_addresses(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->macAddresses->contains($mac));
    }

    public function test_ip_address_linked_to_mac_via_pivot(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'auth', 'last_seen_at' => now()]);

        $this->assertTrue($ip->currentMac()->is($mac));
    }

    public function test_mac_address_unique_constraint(): void
    {
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $this->expectException(QueryException::class);
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    }

    public function test_xbox_factory_state(): void
    {
        $mac = MacAddress::factory()->xbox()->create();

        $this->assertSame('xbox', $mac->source);
        $this->assertSame('Xbox Console', $mac->description);
    }
}
