<?php

namespace Tests\Feature\Console;

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
}
