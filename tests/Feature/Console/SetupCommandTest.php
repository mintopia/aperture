<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_command_creates_first_admin_and_borealis_config(): void
    {
        $result = $this->artisan('aperture:setup', [
            '--admin-email' => 'admin@test.com',
            '--admin-password' => 'secret123',
            '--admin-nickname' => 'Admin',
            '--borealis-endpoint' => 'https://auth.example.com',
            '--borealis-client-id' => 'client-id',
            '--borealis-client-secret' => 'client-secret',
        ]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->expectsOutputToContain('Aperture setup complete.');
        $result->assertSuccessful();
    }

    public function test_setup_fails_without_email_and_password_when_no_users_exist(): void
    {
        $result = $this->artisan('aperture:setup');
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->expectsOutputToContain('First-time setup requires --admin-email and --admin-password options.');
        $result->assertFailed();
    }

    public function test_setup_derives_nickname_from_email_when_not_provided(): void
    {
        $this->artisan('aperture:setup', [
            '--admin-email' => 'jane@example.com',
            '--admin-password' => 'secret123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['nickname' => 'jane']);
    }

    public function test_setup_uses_admin_as_nickname_when_email_has_no_local_part(): void
    {
        // Simulate an edge case where Str::before returns empty string for '@...' format
        $this->artisan('aperture:setup', [
            '--admin-email' => '@example.com',
            '--admin-password' => 'secret123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['nickname' => 'admin']);
    }

    public function test_setup_grants_admin_role_to_existing_user_without_admin_role(): void
    {
        $user = User::factory()->create();
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();

        $this->artisan('aperture:setup')
            ->expectsOutputToContain('Granted admin role to first user')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_setup_does_not_grant_duplicate_admin_role_to_existing_admin(): void
    {
        $user = User::factory()->create();
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();
        $user->roles()->attach($adminRole);

        $result = $this->artisan('aperture:setup');
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertSuccessful();

        // Should not output "Granted admin role" since user is already admin
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_setup_fails_when_only_partial_borealis_config_provided(): void
    {
        $user = User::factory()->create();
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();
        $user->roles()->attach($adminRole);

        $result = $this->artisan('aperture:setup', [
            '--borealis-endpoint' => 'https://auth.example.com',
            '--borealis-client-id' => 'client-id',
            // missing --borealis-client-secret
        ]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->expectsOutputToContain('Provide --borealis-endpoint, --borealis-client-id, and --borealis-client-secret together.');
        $result->assertFailed();
    }

    public function test_setup_warns_when_no_borealis_config_provided(): void
    {
        $user = User::factory()->create();
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();
        $user->roles()->attach($adminRole);

        $result = $this->artisan('aperture:setup');
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->expectsOutputToContain('Borealis OAuth settings were not provided');
        $result->assertSuccessful();
    }
}
