<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeatpickerEventsRouteTest extends TestCase
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

    public function test_seatpicker_events_route_exists(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/v1/events' => Http::response(['data' => [
                ['code' => 'test-event', 'name' => 'Test Event'],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/seatpicker/events', [
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
        ]);

        $response->assertStatus(200);
    }

    public function test_seatpicker_events_requires_auth(): void
    {
        Queue::fake();

        $response = $this->postJson('/admin/settings/integrations/seatpicker/events', [
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
        ]);

        // Unauthenticated users should be redirected or get 401
        $this->assertTrue(
            in_array($response->getStatusCode(), [302, 401, 403]),
            'Expected redirect or auth error for unauthenticated request, got '.$response->getStatusCode(),
        );
    }
}
