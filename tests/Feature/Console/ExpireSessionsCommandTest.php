<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_ips_past_expiry(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->expires_at = now()->subHour();
        $ip->save();

        $this->artisan('aperture:expire-sessions')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
    }

    public function test_does_not_expire_ips_before_expiry(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->expires_at = now()->addDay();
        $ip->save();

        $this->artisan('aperture:expire-sessions')
            ->assertSuccessful();

        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.2']);
    }

    public function test_does_not_expire_ips_without_expiry(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.3';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->expires_at = null;
        $ip->save();

        $this->artisan('aperture:expire-sessions')
            ->assertSuccessful();

        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.3']);
    }

    public function test_unlimits_limited_ips_before_expiry(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.4';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->rate_limit_enabled = true;
        $ip->expires_at = now()->subHour();
        $ip->save();

        $this->artisan('aperture:expire-sessions')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.4']);
    }

    public function test_skips_non_allowed_expired_ips(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.5';
        $ip->last_seen_at = now();
        $ip->internet_enabled = false;
        $ip->expires_at = now()->subHour();
        $ip->save();

        $this->artisan('aperture:expire-sessions')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.5']);
    }
}
