<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SshProxy;

use App\Models\SwitchConfig;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\SwitchProxyExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class SwitchProxyExecutorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function executor(CommandResult $result): SwitchProxyExecutor
    {
        $client = Mockery::mock(SshProxyClientInterface::class);
        $client->shouldReceive('execute')->andReturn($result);

        return new SwitchProxyExecutor($client);
    }

    public function test_pins_observed_key_on_first_success(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->executor(new CommandResult(true, [], hostKey: 'ssh-ed25519 AAA'))->execute($switch, []);

        $this->assertSame('ssh-ed25519 AAA', $switch->refresh()->host_key);
    }

    public function test_does_not_overwrite_existing_pin(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => 'ssh-ed25519 OLD']);

        $this->executor(new CommandResult(true, [], hostKey: 'ssh-ed25519 NEW'))->execute($switch, []);

        $this->assertSame('ssh-ed25519 OLD', $switch->refresh()->host_key);
    }

    public function test_does_not_pin_on_failure(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->executor(new CommandResult(false, [], 'x', hostKey: 'ssh-ed25519 AAA'))->execute($switch, []);

        $this->assertNull($switch->refresh()->host_key);
    }

    public function test_does_not_touch_unsaved_switch(): void
    {
        $switch = SwitchConfig::factory()->make();

        $this->executor(new CommandResult(true, [], hostKey: 'ssh-ed25519 AAA'))->execute($switch, []);

        $this->assertNull($switch->host_key);
    }

    public function test_pin_is_atomic_against_a_stale_model(): void
    {
        $stale = SwitchConfig::factory()->create();
        SwitchConfig::whereKey($stale->id)->update(['host_key' => 'ssh-ed25519 WINNER']);

        $this->executor(new CommandResult(true, [], hostKey: 'ssh-ed25519 LOSER'))->execute($stale, []);

        $this->assertSame('ssh-ed25519 WINNER', $stale->host_key);
        $this->assertSame('ssh-ed25519 WINNER', SwitchConfig::find($stale->id)->host_key);
    }
}
