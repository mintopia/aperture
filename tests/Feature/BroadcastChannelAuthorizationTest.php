<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
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

    private function getAdminEventsChannelCallback(): callable
    {
        $channels = Broadcast::getChannels();

        return $channels['admin.events'];
    }

    public function test_admin_events_channel_is_registered(): void
    {
        $channels = Broadcast::getChannels();

        $this->assertArrayHasKey('admin.events', $channels);
    }

    public function test_admin_events_channel_authorizes_admin_user(): void
    {
        $user = $this->createAdminUser();
        $callback = $this->getAdminEventsChannelCallback();

        $result = $callback($user);

        $this->assertTrue($result);
    }

    public function test_admin_events_channel_rejects_non_admin_user(): void
    {
        $user = User::factory()->create();
        $callback = $this->getAdminEventsChannelCallback();

        $result = $callback($user);

        $this->assertFalse($result);
    }

    public function test_admin_events_channel_rejects_user_with_non_admin_role(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'user';
        $role->name = 'User';
        $role->save();
        $user->roles()->attach($role);

        $callback = $this->getAdminEventsChannelCallback();

        $result = $callback($user);

        $this->assertFalse($result);
    }

    public function test_admin_user_has_admin_role(): void
    {
        $user = $this->createAdminUser();

        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_regular_user_does_not_have_admin_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole('admin'));
    }

    private function getUserChannelCallback(): callable
    {
        $channels = Broadcast::getChannels();

        return $channels['user.{id}'];
    }

    public function test_user_channel_is_registered(): void
    {
        $channels = Broadcast::getChannels();

        $this->assertArrayHasKey('user.{id}', $channels);
    }

    public function test_user_channel_authorizes_matching_user(): void
    {
        $user = User::factory()->create();
        $callback = $this->getUserChannelCallback();

        $result = $callback($user, $user->id);

        $this->assertTrue($result);
    }

    public function test_user_channel_rejects_different_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $callback = $this->getUserChannelCallback();

        $result = $callback($user, $otherUser->id);

        $this->assertFalse($result);
    }

    public function test_user_channel_authorizes_with_string_id(): void
    {
        $user = User::factory()->create();
        $callback = $this->getUserChannelCallback();

        $result = $callback($user, (string) $user->id);

        $this->assertTrue($result);
    }

    public function test_broadcast_routes_are_registered(): void
    {
        $routes = $this->app->make('router')->getRoutes();
        $broadcastAuthRoute = $routes->getByAction('Illuminate\Broadcasting\BroadcastController@authenticate');

        $this->assertNotNull($broadcastAuthRoute);
    }

    public function test_broadcast_auth_requires_authentication(): void
    {
        $response = $this->post('/broadcasting/auth', [
            'socket_id' => '1234.1234567',
            'channel_name' => 'private-admin.events',
        ]);

        // Web middleware with auth redirects unauthenticated users
        $response->assertRedirect();
    }
}
