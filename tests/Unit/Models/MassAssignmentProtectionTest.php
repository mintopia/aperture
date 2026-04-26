<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MassAssignmentProtectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── User ────────────────────────────────────────────────────────

    public function test_user_allows_mass_assignment_of_fillable_fields(): void
    {
        $user = User::factory()->create([
            'nickname' => 'TestUser',
            'email' => 'test@example.com',
            'internet_blocked' => true,
            'internet_enabled' => true,
            'rate_limit_enabled' => true,
            'dns_filtering_enabled' => true,
        ]);

        $this->assertSame('TestUser', $user->nickname);
        $this->assertSame('test@example.com', $user->email);
        $this->assertTrue($user->internet_blocked);
        $this->assertTrue($user->internet_enabled);
        $this->assertTrue($user->rate_limit_enabled);
        $this->assertTrue($user->dns_filtering_enabled);
    }

    public function test_user_allows_mass_assignment_of_oauth_fields(): void
    {
        $user = User::factory()->withAuth()->create();

        $this->assertNotNull($user->external_id);
        $this->assertNotNull($user->access_token);
        $this->assertNotNull($user->refresh_token);
        $this->assertNotNull($user->token_expires_at);
        $this->assertNotNull($user->avatar_url);
    }

    public function test_user_allows_mass_assignment_of_password(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $this->assertNotNull($user->password);
    }

    public function test_user_guards_id_from_mass_assignment(): void
    {
        $user = new User;
        $user->fill(['id' => 999, 'nickname' => 'Test']);

        $this->assertNull($user->id);
        $this->assertSame('Test', $user->nickname);
    }

    // ── IpAddress ──────────────────────────────────────────────────

    public function test_ip_address_allows_mass_assignment_of_fillable_fields(): void
    {
        $ip = new IpAddress;
        $ip->fill([
            'address' => '192.168.1.100',
            'internet_enabled' => true,
            'rate_limit_enabled' => true,
            'dns_filtering_enabled' => true,
            'comment' => 'Test comment',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->assertSame('192.168.1.100', $ip->address);
        $this->assertTrue($ip->internet_enabled);
        $this->assertTrue($ip->rate_limit_enabled);
        $this->assertTrue($ip->dns_filtering_enabled);
        $this->assertSame('Test comment', $ip->comment);
        $this->assertNotNull($ip->last_seen_at);
        $this->assertNotNull($ip->expires_at);
    }

    public function test_ip_address_guards_id_from_mass_assignment(): void
    {
        $ip = new IpAddress;
        $ip->fill(['id' => 999, 'address' => '10.0.0.1']);

        $this->assertNull($ip->id);
        $this->assertSame('10.0.0.1', $ip->address);
    }

    public function test_ip_address_factory_still_works(): void
    {
        $ip = IpAddress::factory()->create();

        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id]);
    }

    // ── UserIpAddress ──────────────────────────────────────────────

    public function test_user_ip_address_allows_mass_assignment_of_fillable_fields(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();

        $userIp = new UserIpAddress;
        $userIp->fill([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
            'last_seen_at' => now(),
        ]);

        $this->assertEquals($user->id, $userIp->user_id);
        $this->assertEquals($ip->id, $userIp->ip_address_id);
        $this->assertNotNull($userIp->last_seen_at);
    }

    public function test_user_ip_address_guards_id_from_mass_assignment(): void
    {
        $userIp = new UserIpAddress;
        $userIp->fill(['id' => 999, 'last_seen_at' => now()]);

        $this->assertNull($userIp->id);
        $this->assertNotNull($userIp->last_seen_at);
    }

    // ── Role ───────────────────────────────────────────────────────

    public function test_role_allows_mass_assignment_of_fillable_fields(): void
    {
        $role = new Role;
        $role->fill([
            'code' => 'moderator',
            'name' => 'Moderator',
        ]);

        $this->assertSame('moderator', $role->code);
        $this->assertSame('Moderator', $role->name);
    }

    public function test_role_guards_id_from_mass_assignment(): void
    {
        $role = new Role;
        $role->fill(['id' => 999, 'code' => 'test']);

        $this->assertNull($role->id);
        $this->assertSame('test', $role->code);
    }

    public function test_role_can_still_be_created_manually(): void
    {
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Administrator';
        $role->save();

        $this->assertDatabaseHas('roles', ['code' => 'admin', 'name' => 'Administrator']);
    }
}
