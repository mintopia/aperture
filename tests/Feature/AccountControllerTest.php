<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

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

    public function test_authenticated_user_can_access_account_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Account/Settings')
            ->has('user')
            ->has('verified')
        );
    }

    public function test_guest_cannot_access_account_settings(): void
    {
        $response = $this->get('/account/settings');

        $response->assertRedirect(route('captive.index'));
    }

    public function test_user_without_password_sees_settings_directly(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Account/Settings')
            ->where('user.has_password', false)
            ->where('verified', false)
        );
    }

    public function test_user_with_password_must_verify(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Account/Settings')
            ->where('user.has_password', true)
            ->where('verified', false)
        );
    }

    public function test_user_can_verify_with_correct_password(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $response = $this->actingAs($user)->post('/account/settings/verify', [
            'password' => 'secret123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_verified', true);
    }

    public function test_user_cannot_verify_with_wrong_password(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $response = $this->actingAs($user)->post('/account/settings/verify', [
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_user_can_clear_password(): void
    {
        $user = User::factory()->withPassword('oldpass123')->create();

        // Set account_verified in session first
        $this->actingAs($user);
        session()->put('account_verified', true);

        $response = $this->actingAs($user)->delete('/account/settings/password');

        $response->assertRedirect();
        $user->refresh();
        $this->assertNull($user->password);
    }

    public function test_password_update_requires_confirmation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_update_requires_minimum_length(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_clear_password_forgets_verified_session(): void
    {
        $user = User::factory()->withPassword('oldpass123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $this->actingAs($user)->delete('/account/settings/password');

        $this->assertFalse(session()->has('account_verified'));
    }

    public function test_settings_page_shows_user_data(): void
    {
        $user = User::factory()->create(['nickname' => 'TestUser', 'email' => 'test@example.com']);

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertInertia(fn ($page) => $page
            ->where('user.nickname', 'TestUser')
            ->where('user.email', 'test@example.com')
        );
    }

    public function test_verified_session_flag_is_passed_to_page(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertInertia(fn ($page) => $page
            ->where('verified', true)
        );
    }
}
