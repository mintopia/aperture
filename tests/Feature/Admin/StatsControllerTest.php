<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\AggregateStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StatsControllerTest extends TestCase
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

    public function test_admin_can_view_stats_index(): void
    {
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getAggregateStats')->once()->andReturn(new AggregateStats(totalUsers: 0, totalDevices: 0, totalBandwidth: 1024));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/stats');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Stats/Index')->has('aggregateStats'));
    }

    public function test_admin_can_view_bandwidth(): void
    {
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getTopTalkers')->once()->andReturn(collect([]));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/stats/bandwidth');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Stats/Bandwidth')->has('topTalkers'));
    }

    public function test_admin_can_get_top_talkers_json(): void
    {
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getTopTalkers')->once()->andReturn(collect([
            ['ip' => '10.0.0.1', 'bytes' => 1024],
        ]));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->getJson('/admin/stats/top-talkers');

        $response->assertOk();
        $response->assertJsonStructure(['topTalkers']);
    }
}
