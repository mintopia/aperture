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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function addressNormalizationOnCreateProvider(): array
    {
        return [
            'ipv6 is lowercased' => ['2001:DB8::ABCD:1', '2001:db8::abcd:1'],
            'ipv4 is unaffected' => ['10.30.0.1', '10.30.0.1'],
        ];
    }

    #[DataProvider('addressNormalizationOnCreateProvider')]
    public function test_address_is_normalized_on_create(string $address, string $expected): void
    {
        $ip = IpAddress::create([
            'address' => $address,
            'last_seen_at' => now(),
        ]);

        $this->assertEquals($expected, $ip->address);
        $this->assertDatabaseHas('ip_addresses', ['address' => $expected]);
    }

    public static function normalizeProvider(): array
    {
        return [
            'lowercases ipv6 addresses' => ['2001:DB8::ABCD:1', '2001:db8::abcd:1'],
            'leaves ipv4 addresses unchanged' => ['10.30.0.1', '10.30.0.1'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize(string $address, string $expected): void
    {
        $this->assertSame($expected, IpAddress::normalize($address));
    }

    public static function togglingDispatchesSyncJobProvider(): array
    {
        return [
            'enabling rate limit dispatches SyncRateLimitJob' => ['rate_limit_enabled', false, true, SyncRateLimitJob::class],
            'disabling rate limit dispatches SyncRateLimitJob' => ['rate_limit_enabled', true, false, SyncRateLimitJob::class],
            'enabling internet dispatches SyncInternetAccessJob' => ['internet_enabled', false, true, SyncInternetAccessJob::class],
            'disabling internet dispatches SyncInternetAccessJob' => ['internet_enabled', true, false, SyncInternetAccessJob::class],
        ];
    }

    #[DataProvider('togglingDispatchesSyncJobProvider')]
    public function test_toggling_flag_dispatches_sync_job(string $attribute, bool $initial, bool $new, string $expectedJob): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->{$attribute} = $initial;
        $ip->save();

        $ip->{$attribute} = $new;
        $ip->save();
        Queue::assertPushed($expectedJob);
    }
}
