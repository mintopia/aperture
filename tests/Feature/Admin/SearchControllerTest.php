<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_search_finds_users_by_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['nickname' => 'TestPlayer']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=TestPlayer');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.nickname', 'TestPlayer');
    }

    public function test_search_finds_users_by_email(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['email' => 'findme@example.com']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=findme');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
    }

    public function test_search_finds_ips_by_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = new IpAddress;
        $ip->address = '192.168.1.50';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=192.168.1.50');

        $response->assertOk();
        $response->assertJsonCount(1, 'ips');
    }

    public function test_search_returns_grouped_results(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=test');

        $response->assertOk();
        $response->assertJsonStructure(['users', 'ips']);
    }

    public function test_search_rejects_short_queries(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=a');

        $response->assertStatus(422);
    }

    public function test_non_admin_cannot_search(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/admin/search?q=test');

        $response->assertForbidden();
    }

    public function test_search_rejects_missing_query(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search');

        $response->assertStatus(422);
    }

    public function test_search_rejects_query_exceeding_max_length(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q='.str_repeat('a', 101));

        $response->assertStatus(422);
    }

    public function test_search_escapes_percent_metacharacter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['nickname' => 'normal_user']);

        // A query of '%' would match everything without escaping; with escaping it matches nothing
        $response = $this->actingAs($admin)->getJson('/admin/search?q=%_');

        $response->assertOk();
        $response->assertJsonCount(0, 'users');
    }

    public function test_search_escapes_underscore_metacharacter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        // Create a user whose nickname does NOT contain a literal underscore
        User::factory()->create(['nickname' => 'abcde']);

        // '_' without escaping would act as a wildcard and match 'abcde'
        // With escaping it only matches a literal '_', so zero results expected
        $response = $this->actingAs($admin)->getJson('/admin/search?q=___');

        $response->assertOk();
        $response->assertJsonCount(0, 'users');
    }
}
