<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventSettingsControllerTest extends TestCase
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

    public function test_admin_can_view_event_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/event');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Event'));
    }

    public function test_admin_can_update_event_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/event', [
            'event_name' => 'Epic LAN 42',
            'event_description' => 'The best LAN party ever',
        ]);

        $response->assertRedirect();
        $this->assertEquals('Epic LAN 42', Setting::get('event.name'));
        $this->assertEquals('The best LAN party ever', Setting::get('event.description'));
    }

    public function test_event_name_is_required(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/event', [
            'event_name' => '',
            'event_description' => 'A description',
        ]);

        $response->assertSessionHasErrors('event_name');
    }

    public function test_event_description_is_optional(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/event', [
            'event_name' => 'My Event',
            'event_description' => '',
        ]);

        $response->assertRedirect();
        $this->assertEquals('My Event', Setting::get('event.name'));
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }
        $setting->value = $value;
        $setting->save();
    }

    public function test_non_admin_cannot_access_event_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/event')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/event', [
            'event_name' => 'Test',
        ])->assertForbidden();
    }

    public function test_event_show_returns_correct_settings_structure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('event.name', 'Event Name', 'LAN Party');
        $this->saveSetting('event.description', 'Event Description', 'Fun times');

        $response = $this->actingAs($admin)->get('/admin/settings/event');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Event')
            ->has('settings')
            ->where('settings.event_name', 'LAN Party')
            ->where('settings.event_description', 'Fun times')
        );
    }
}
