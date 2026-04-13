<?php

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
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

        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://127.0.0.1:19199');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'verify_ssl', '0');
        IntegrationConfig::setValue('opnsense', 'zone_id', '1');
        IntegrationConfig::setValue('opnsense', 'ratelimit_up_uuid', 'up-uuid');
        IntegrationConfig::setValue('opnsense', 'ratelimit_down_uuid', 'down-uuid');
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
