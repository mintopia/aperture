<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

class SshProxyCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('aperture:ssh-proxy')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_disabled(): void
    {
        config(['aperture.ssh_proxy.enabled' => false]);

        $this->artisan('aperture:ssh-proxy')
            ->expectsOutputToContain('SSH proxy is not enabled')
            ->assertExitCode(1);
    }

    public function test_command_signature_correct(): void
    {
        $command = $this->app->make(Kernel::class)
            ->all()['aperture:ssh-proxy'] ?? null;

        $this->assertNotNull($command);
        $this->assertSame('aperture:ssh-proxy', $command->getName());
    }
}
