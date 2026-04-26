<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserIpAddressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_returns_belongs_to_relationship(): void
    {
        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->assertInstanceOf(BelongsTo::class, $userIp->user());
        $this->assertEquals($user->id, $userIp->user->id);
    }

    public function test_ip_returns_belongs_to_relationship(): void
    {
        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->assertInstanceOf(BelongsTo::class, $userIp->ip());
        $this->assertEquals($ip->id, $userIp->ip->id);
    }

    public function test_last_seen_at_is_cast_to_datetime(): void
    {
        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.3';
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $fresh = UserIpAddress::find($userIp->id);
        $this->assertInstanceOf(Carbon::class, $fresh->last_seen_at);
    }
}
