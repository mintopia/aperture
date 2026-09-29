<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\UserNetworkAssociationService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_be_created_with_factory(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_ips_returns_has_many_relationship(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->ips());
    }

    public function test_roles_returns_belongs_to_many_relationship(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(BelongsToMany::class, $user->roles());
    }

    public function test_has_role_returns_true_when_user_has_role(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Admin';
        $role->code = 'admin';
        $role->save();
        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_has_role_returns_false_when_user_does_not_have_role(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_has_role_accepts_role_model_instance(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Admin';
        $role->code = 'admin';
        $role->save();
        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole($role));
    }

    public function test_has_role_caches_roles_for_request_lifecycle(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Admin';
        $role->code = 'admin';
        $role->save();
        $user->roles()->attach($role);

        $user = User::findOrFail($user->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->hasRole('admin');
        $user->hasRole('admin');
        $user->hasRole('nonexistent');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $roleQueries = array_filter($queries, function (array $query): bool {
            return str_contains($query['query'], 'roles') || str_contains($query['query'], 'role_user');
        });

        $this->assertCount(1, $roleQueries, 'hasRole() should only query the database once for roles, not on every call');
    }

    public function test_has_role_uses_loaded_roles_relation(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Editor';
        $role->code = 'editor';
        $role->save();
        $user->roles()->attach($role);

        $user->load('roles');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($user->hasRole('editor'));
        $this->assertFalse($user->hasRole('admin'));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $roleQueries = array_filter($queries, function (array $query): bool {
            return str_contains($query['query'], 'roles') || str_contains($query['query'], 'role_user');
        });

        $this->assertCount(0, $roleQueries, 'hasRole() should not query the database when roles are already loaded');
    }

    public function test_add_ip_creates_new_ip_and_user_ip_address(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip = resolve(UserNetworkAssociationService::class)->addIp($user, '192.168.1.100');

        $this->assertInstanceOf(IpAddress::class, $ip);
        $this->assertEquals('192.168.1.100', $ip->address);
        $this->assertDatabaseHas('ip_addresses', ['address' => '192.168.1.100']);
        $this->assertDatabaseHas('user_ip_addresses', [
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);
    }

    public function test_add_ip_reuses_existing_ip_address(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip1 = resolve(UserNetworkAssociationService::class)->addIp($user, '192.168.1.100');
        $ip2 = resolve(UserNetworkAssociationService::class)->addIp($user, '192.168.1.100');

        $this->assertNotNull($ip1);
        $this->assertNotNull($ip2);
        $this->assertEquals($ip1->id, $ip2->id);
        $this->assertEquals(1, IpAddress::whereAddress('192.168.1.100')->count());
    }

    public function test_to_string_returns_nickname(): void
    {
        $user = User::factory()->create(['nickname' => 'TestUser']);
        $this->assertStringContainsString('TestUser', (string) $user);
        $this->assertStringContainsString('[User:', (string) $user);
    }

    public function test_oauth_tokens_are_not_in_fillable(): void
    {
        $user = new User;
        $fillable = $user->getFillable();

        $this->assertNotContains('access_token', $fillable, 'access_token should not be in $fillable');
        $this->assertNotContains('refresh_token', $fillable, 'refresh_token should not be in $fillable');
        $this->assertNotContains('token_expires_at', $fillable, 'token_expires_at should not be in $fillable');
    }

    public function test_oauth_tokens_assigned_directly_are_encrypted_at_rest(): void
    {
        $user = new User;
        $user->access_token = 'plain-token-value';
        $user->refresh_token = 'plain-refresh-value';

        $attributes = $user->getAttributes();
        $this->assertNotSame('plain-token-value', $attributes['access_token']);
        $this->assertNotSame('plain-refresh-value', $attributes['refresh_token']);
        $this->assertSame('plain-token-value', $user->access_token);
        $this->assertSame('plain-refresh-value', $user->refresh_token);
    }

    public function test_mass_assignment_does_not_set_oauth_tokens(): void
    {
        $user = new User;
        $user->fill([
            'access_token' => 'should-not-be-set',
            'refresh_token' => 'should-not-be-set',
            'token_expires_at' => '2099-01-01 00:00:00',
            'nickname' => 'TestUser',
        ]);

        $attributes = $user->getAttributes();
        $this->assertArrayNotHasKey('access_token', $attributes, 'access_token should not be set via mass assignment');
        $this->assertArrayNotHasKey('refresh_token', $attributes, 'refresh_token should not be set via mass assignment');
        $this->assertArrayNotHasKey('token_expires_at', $attributes, 'token_expires_at should not be set via mass assignment');
        $this->assertArrayHasKey('nickname', $attributes, 'nickname should still be fillable');
    }
}
