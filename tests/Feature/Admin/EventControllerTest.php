<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Events/Index')
            ->has('events.data', 1)
            ->has('totalCount')
            ->has('filters')
            ->has('breadcrumbs')
        );
    }

    public function test_non_admin_cannot_access(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/events')->assertForbidden();
    }

    public function test_search_filters_by_type(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'alice connected']);
        SystemEvent::factory()->create(['type' => 'SwitchUnreachable', 'message' => 'switch down']);

        $response = $this->actingAs($admin)->get('/admin/events?search=UserConnected');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', 'UserConnected')
        );
    }

    public function test_search_filters_by_message(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'alice connected from 10.0.0.42']);
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'bob connected from 10.0.0.43']);

        $response = $this->actingAs($admin)->get('/admin/events?search=alice');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.message', 'alice connected from 10.0.0.42')
        );
    }

    public function test_total_count_reflects_unfiltered_total(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->count(5)->create(['type' => 'UserConnected', 'message' => 'user connected']);
        SystemEvent::factory()->count(3)->create(['type' => 'SwitchUnreachable', 'message' => 'switch down']);

        $response = $this->actingAs($admin)->get('/admin/events?search=UserConnected');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 5)
            ->where('totalCount', 8)
        );
    }

    public function test_pagination_works(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get('/admin/events?perPage=10');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 10)
        );
    }

    public function test_ordered_by_created_at_desc(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create(['message' => 'old', 'created_at' => now()->subHour()]);
        SystemEvent::factory()->create(['message' => 'new', 'created_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('events.data.0.message', 'new')
            ->where('events.data.1.message', 'old')
        );
    }

    public function test_events_include_expected_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test event',
            'data' => ['key' => 'value'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data.0', fn ($event) => $event
                ->has('id')
                ->where('type', 'UserConnected')
                ->where('level', 'info')
                ->where('message', 'test event')
                ->has('created_at')
                ->etc()
            )
        );
    }
}
