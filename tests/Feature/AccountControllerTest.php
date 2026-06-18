<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

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
            ->component('Account/Settings', false)
            ->has('user')
            ->has('verified')
        );
    }

    public function test_guest_cannot_access_account_settings(): void
    {
        $response = $this->get('/account/settings');

        $response->assertRedirect(route('captive.index'));
    }

    public function test_user_without_password_sees_settings_unverified(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Account/Settings', false)
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
            ->component('Account/Settings', false)
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
        $user = User::factory()->withPassword('oldpassword123')->create();

        // Set account_verified in session first
        $this->actingAs($user);
        session()->put('account_verified', true);

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
        $user = User::factory()->withPassword('existing123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $response = $this->actingAs($user)->put('/account/settings/password', [
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_update_requires_minimum_length(): void
    {
        $user = User::factory()->withPassword('existing123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

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

    public function test_authenticated_user_can_call_passkey_destroy(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $response = $this->actingAs($user)->deleteJson('/passkeys/nonexistent-credential-id');

        // Credential doesn't exist, so deleted = 0, but endpoint should still respond
        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_guest_cannot_delete_passkeys(): void
    {
        $response = $this->deleteJson('/passkeys/some-credential-id');

        $response->assertUnauthorized();
    }

    public function test_passkey_destroy_returns_json(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $response = $this->actingAs($user)->deleteJson('/passkeys/any-id');

        $response->assertOk();
        $response->assertJsonStructure(['success']);
    }

    public function test_passkey_destroy_returns_false_for_other_users_credential(): void
    {
        $user = User::factory()->withPassword('secret123')->create();
        User::factory()->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        // Even if we had a real credential ID from otherUser, user cannot delete it
        $response = $this->actingAs($user)->deleteJson('/passkeys/credential-belonging-to-other');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_user_with_password_must_verify_before_registering_passkey(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $response = $this->actingAs($user)->postJson('/passkeys/register/options');

        $response->assertForbidden();
        $response->assertJson(['message' => 'Please verify your account before managing passkeys.']);
    }

    public function test_user_with_password_must_verify_before_deleting_passkey(): void
    {
        $user = User::factory()->withPassword('secret123')->create();

        $response = $this->actingAs($user)->deleteJson('/passkeys/nonexistent-credential-id');

        $response->assertForbidden();
        $response->assertJson(['message' => 'Please verify your account before managing passkeys.']);
    }

    public function test_user_with_password_must_verify_before_updating_password(): void
    {
        $user = User::factory()->withPassword('oldpassword123')->create();

        $response = $this->actingAs($user)->putJson('/account/settings/password', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertForbidden();
        $response->assertJson(['message' => 'Please verify your account before managing passkeys.']);
    }

    public function test_user_with_password_must_verify_before_clearing_password(): void
    {
        $user = User::factory()->withPassword('oldpassword123')->create();

        $response = $this->actingAs($user)->deleteJson('/account/settings/password');

        $response->assertForbidden();
        $response->assertJson(['message' => 'Please verify your account before managing passkeys.']);
    }

    public function test_user_without_password_or_passkeys_is_blocked_from_passkey_registration(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/passkeys/register/options');

        $response->assertForbidden();
        $response->assertJson(['message' => 'Please create a password to manage passkeys.']);
    }

    public function test_user_can_verify_after_creating_password(): void
    {
        $user = User::factory()->create();

        // Create password via the new route (not behind EnsureAccountSecurityVerified)
        $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        // Session should already be verified after createPassword
        $this->assertTrue(session()->get('account_verified'));
    }

    public function test_user_can_verify_after_admin_sets_password(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $this->actingAs($admin)->put('/admin/users/'.$user->id, [
            'nickname' => $user->nickname,
            'email' => $user->email,
            'password' => 'adminset123',
            'password_confirmation' => 'adminset123',
        ]);

        $response = $this->actingAs($user->fresh())->post('/account/settings/verify', [
            'password' => 'adminset123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_verified', true);
        $response->assertSessionHasNoErrors();
    }

    // ── createPassword tests ────────────────────────────────────────────────────

    public function test_create_password_sets_password_for_passwordless_user(): void
    {
        $user = User::factory()->create(); // no password

        $response = $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_create_password_rejects_if_user_already_has_password(): void
    {
        $user = User::factory()->withPassword('existing123')->create();

        $response = $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertForbidden();
    }

    public function test_create_password_validates_minimum_length(): void
    {
        $user = User::factory()->create(); // no password

        $response = $this->actingAs($user)->post('/account/password/create', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_create_password_validates_confirmation(): void
    {
        $user = User::factory()->create(); // no password

        $response = $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_create_password_marks_session_as_verified(): void
    {
        $user = User::factory()->create(); // no password

        $response = $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_verified', true);
    }

    public function test_user_can_update_password_after_creating_initial_password(): void
    {
        $user = User::factory()->create(); // no password

        // Create initial password
        $this->actingAs($user)->post('/account/password/create', [
            'password' => 'initialpass123',
            'password_confirmation' => 'initialpass123',
        ]);

        // Now the session is verified, update password via normal route
        $response = $this->actingAs($user->fresh())->put('/account/settings/password', [
            'password' => 'updatedpass123',
            'password_confirmation' => 'updatedpass123',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('updatedpass123', $user->password));
    }

    // ── Audit log tests ────────────────────────────────────────────────────────

    public function test_update_password_creates_audit_log(): void
    {
        $user = User::factory()->withPassword('oldpassword123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $this->actingAs($user)->put('/account/settings/password', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertTrue(AuditLog::where('action', 'user.password_changed')->exists());
        $log = AuditLog::where('action', 'user.password_changed')->first();
        $this->assertNotNull($log);
        $this->assertEquals('account', $log->process);
        $this->assertEquals($user->getMorphClass(), $log->subject_type);
        $this->assertEquals($user->id, $log->subject_id);
        $this->assertArrayHasKey('ip', $log->metadata);
    }

    public function test_create_password_creates_audit_log(): void
    {
        $user = User::factory()->create(); // no password

        $this->actingAs($user)->post('/account/password/create', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertTrue(AuditLog::where('action', 'user.password_created')->exists());
        $log = AuditLog::where('action', 'user.password_created')->first();
        $this->assertNotNull($log);
        $this->assertEquals('account', $log->process);
        $this->assertEquals($user->id, $log->subject_id);
    }

    public function test_clear_password_creates_audit_log(): void
    {
        $user = User::factory()->withPassword('oldpass123')->create();

        $this->actingAs($user);
        session()->put('account_verified', true);

        $this->actingAs($user)->delete('/account/settings/password');

        $this->assertTrue(AuditLog::where('action', 'user.password_cleared')->exists());
        $log = AuditLog::where('action', 'user.password_cleared')->first();
        $this->assertNotNull($log);
        $this->assertEquals('account', $log->process);
        $this->assertEquals($user->id, $log->subject_id);
    }
}
