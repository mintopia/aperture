<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAddressDirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aperture.opnsense.endpoint' => 'http://127.0.0.1:19199',
            'aperture.opnsense.key' => 'key',
            'aperture.opnsense.secret' => 'secret',
            'aperture.opnsense.verify' => false,
            'aperture.opnsense.zoneid' => 1,
            'aperture.opnsense.ratelimitUpUuid' => 'up-uuid',
            'aperture.opnsense.ratelimitDownUuid' => 'down-uuid',
        ]);
    }

    public function test_limit_direct_calls_opn_sense(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.50';
        $ip->last_seen_at = now();
        $ip->limited = false;
        $ip->save();

        $ip->limit(false);

        $ip->refresh();
        $this->assertTrue((bool) $ip->limited);
    }

    public function test_unlimit_direct_calls_opn_sense(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.51';
        $ip->last_seen_at = now();
        $ip->limited = true;
        $ip->save();

        $ip->unlimit(false);

        $ip->refresh();
        $this->assertFalse((bool) $ip->limited);
    }

    public function test_allow_direct_calls_opn_sense(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.52';
        $ip->last_seen_at = now();
        $ip->allowed = false;
        $ip->save();

        $ip->allow(false);

        $ip->refresh();
        $this->assertTrue((bool) $ip->allowed);
    }

    public function test_allow_direct_uses_user_nickname_as_description(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.53';
        $ip->last_seen_at = now();
        $ip->allowed = false;
        $ip->save();

        $user = User::factory()->create(['nickname' => 'TestPlayer']);
        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $ip->allow(false);

        $ip->refresh();
        $this->assertTrue((bool) $ip->allowed);
    }

    public function test_deny_direct_calls_opn_sense(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.54';
        $ip->last_seen_at = now();
        $ip->allowed = true;
        $ip->save();

        $ip->deny(false);

        $ip->refresh();
        $this->assertFalse((bool) $ip->allowed);
    }
}
