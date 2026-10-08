<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\RateLimitingInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ResetApertureJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $job = new ResetAperture;

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $job = new ResetAperture;

        Log::shouldReceive('error')
            ->once()
            ->with('ResetAperture failed', [
                'error' => 'Database error',
            ]);

        $job->failed(new RuntimeException('Database error'));
    }

    public function test_deletes_all_ips(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->save();

        $this->runReset();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
    }

    public function test_unlimits_limited_ips(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->rate_limit_enabled = true;
        $ip->save();

        $this->runReset();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.2']);
    }

    public function test_deletes_non_admin_users(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $this->runReset();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
    }

    public function test_preserves_admin_users(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $this->runReset();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_deletes_mac_addresses_and_all_associations(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);
        IpAddressMacAddress::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id]);

        $userIp = new UserIpAddress;
        $userIp->user_id = $user->id;
        $userIp->ip_address_id = $ip->id;
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->runReset();

        $this->assertDatabaseCount('user_ip_addresses', 0);
        $this->assertDatabaseCount('ip_address_mac_address', 0);
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertDatabaseCount('mac_addresses', 0);
    }

    public function test_deletes_every_row_beyond_a_single_chunk(): void
    {
        IpAddress::factory()->count(250)->sequence(fn ($s) => ['address' => '10.1.'.intdiv($s->index, 200).'.'.($s->index % 200 + 1)])->create();
        User::factory()->count(250)->create();

        $this->runReset();

        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_reverts_only_what_each_ip_has_enabled(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true, 'rate_limit_enabled' => false, 'dns_filtering_enabled' => false]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => false, 'rate_limit_enabled' => true, 'dns_filtering_enabled' => false]);
        IpAddress::factory()->create(['address' => '10.0.0.3', 'internet_enabled' => false, 'rate_limit_enabled' => false, 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.4', 'internet_enabled' => false, 'rate_limit_enabled' => false, 'dns_filtering_enabled' => false]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('removeIp')->once()->with('10.0.0.1');
        $captivePortal->shouldNotReceive('reconcile');
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);
        $rateLimiter->shouldReceive('unlimitIp')->once()->with('10.0.0.2');
        $rateLimiter->shouldNotReceive('reconcile');
        $dnsFiltering = Mockery::mock(DnsFilteringInterface::class);
        $dnsFiltering->shouldReceive('disableForIp')->once()->with('10.0.0.3');
        $dnsFiltering->shouldNotReceive('reconcile');

        (new ResetAperture)->handle($captivePortal, $rateLimiter, $dnsFiltering);

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_keeps_database_and_throws_when_a_removal_fails(): void
    {
        $user = User::factory()->create();
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true, 'rate_limit_enabled' => true, 'dns_filtering_enabled' => false]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => true, 'rate_limit_enabled' => false, 'dns_filtering_enabled' => false]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('removeIp')->with('10.0.0.1')->andThrow(new RuntimeException('timeout'));
        $captivePortal->shouldReceive('removeIp')->once()->with('10.0.0.2');
        $rateLimiter = Mockery::mock(RateLimitingInterface::class);
        $rateLimiter->shouldReceive('unlimitIp')->once()->with('10.0.0.1');
        $dnsFiltering = Mockery::mock(DnsFilteringInterface::class);

        try {
            (new ResetAperture)->handle($captivePortal, $rateLimiter, $dnsFiltering);
            $this->fail('Expected reset to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed to revert IP access during reset: 10.0.0.1: timeout', $e->getMessage());
        }

        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    private function runReset(): void
    {
        (new ResetAperture)->handle(
            Mockery::spy(CaptivePortalInterface::class),
            Mockery::spy(RateLimitingInterface::class),
            Mockery::spy(DnsFilteringInterface::class),
        );
    }
}
