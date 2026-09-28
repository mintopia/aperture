<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SwitchKeyAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc123\n-----END OPENSSH PRIVATE KEY-----\n";

    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Key Switch',
            'hostname' => 'key-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'auth_method' => 'private_key',
            'private_key' => self::KEY,
            'passphrase' => 'hunter2',
            'port' => 22,
            'timeout' => 5,
        ], $overrides);
    }

    public function test_store_with_private_key_saves_encrypted_and_clears_password(): void
    {
        $this->actingAs($this->admin())->post('/admin/switches', $this->payload(['password' => 'ignored']))
            ->assertSessionHasNoErrors();

        $switch = SwitchConfig::firstOrFail();
        $this->assertSame(self::KEY, $switch->private_key);
        $this->assertSame('hunter2', $switch->passphrase);
        $this->assertNull($switch->password);

        $raw = DB::table('switch_configs')->first();
        $this->assertStringNotContainsString('abc123', (string) $raw->private_key);
        $this->assertStringNotContainsString('hunter2', (string) $raw->passphrase);
    }

    public function test_store_with_password_method_drops_key_material(): void
    {
        $this->actingAs($this->admin())->post('/admin/switches', $this->payload([
            'auth_method' => 'password',
            'password' => 'pw',
        ]))->assertSessionHasNoErrors();

        $switch = SwitchConfig::firstOrFail();
        $this->assertSame('pw', $switch->password);
        $this->assertNull($switch->private_key);
        $this->assertNull($switch->passphrase);
    }

    public function test_store_requires_the_secret_for_the_chosen_method(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/switches', $this->payload(['private_key' => '']))
            ->assertSessionHasErrors('private_key');
        $this->actingAs($admin)->post('/admin/switches', $this->payload(['auth_method' => 'password']))
            ->assertSessionHasErrors('password');
        $this->actingAs($admin)->post('/admin/switches', $this->payload(['private_key' => 'not a key']))
            ->assertSessionHasErrors('private_key');
        $this->actingAs($admin)->post('/admin/switches', $this->payload(['auth_method' => 'telepathy']))
            ->assertSessionHasErrors('auth_method');
    }

    public function test_switching_from_password_to_key_clears_password(): void
    {
        $switch = SwitchConfig::factory()->create(['password' => 'old']);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload(['hostname' => $switch->hostname]))
            ->assertSessionHasNoErrors();

        $switch->refresh();
        $this->assertNull($switch->password);
        $this->assertSame(self::KEY, $switch->private_key);
    }

    public function test_switching_from_key_to_password_clears_key_and_passphrase(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp']);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'auth_method' => 'password',
            'password' => 'newpw',
            'private_key' => '',
            'passphrase' => '',
        ]))->assertSessionHasNoErrors();

        $switch->refresh();
        $this->assertSame('newpw', $switch->password);
        $this->assertNull($switch->private_key);
        $this->assertNull($switch->passphrase);
    }

    public function test_switching_method_without_new_secret_is_rejected(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create();

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'auth_method' => 'password',
            'private_key' => '',
        ]))->assertSessionHasErrors('password');
    }

    public function test_update_with_blank_secrets_keeps_existing_key_and_passphrase(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp']);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'private_key' => '',
            'passphrase' => '',
        ]))->assertSessionHasNoErrors();

        $switch->refresh();
        $this->assertStringContainsString('fake', (string) $switch->private_key);
        $this->assertSame('pp', $switch->passphrase);
    }

    public function test_replacing_key_without_passphrase_clears_old_passphrase(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp']);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'passphrase' => '',
        ]))->assertSessionHasNoErrors();

        $switch->refresh();
        $this->assertSame(self::KEY, $switch->private_key);
        $this->assertNull($switch->passphrase);
    }

    public function test_changing_hostname_clears_pinned_host_key(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => 'other.local',
            'auth_method' => 'password',
            'password' => '',
            'private_key' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertNull($switch->refresh()->host_key);
    }

    public function test_secrets_are_never_sent_to_the_browser(): void
    {
        $admin = $this->admin();
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp', 'host_key' => self::HOST_KEY]);

        foreach (['/admin/switches/'.$switch->id, '/admin/switches/'.$switch->id.'/edit'] as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->where('switchConfig.has_private_key', true)
                ->where('switchConfig.has_passphrase', true)
                ->where('switchConfig.has_password', false)
                ->missing('switchConfig.private_key')
                ->missing('switchConfig.passphrase')
                ->missing('switchConfig.password')
                ->where('switchConfig.host_key', self::HOST_KEY)
                ->where('switchConfig.host_key_fingerprint', 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode(explode(' ', self::HOST_KEY)[1]), true)), '='))
                ->etc());
            $this->assertStringNotContainsString('fake', $response->getContent());
            $this->assertStringNotContainsString('pp&quot;', $response->getContent());
        }
    }

    public function test_model_serialisation_hides_key_material(): void
    {
        $array = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp'])->toArray();

        $this->assertArrayNotHasKey('private_key', $array);
        $this->assertArrayNotHasKey('passphrase', $array);
    }

    public function test_reset_host_key_clears_pin_and_audits(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $this->actingAs($this->admin())->delete('/admin/switches/'.$switch->id.'/host-key')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($switch->refresh()->host_key);
        $this->assertTrue(AuditLog::where('action', 'switch.host_key_reset')->exists());
    }

    public function test_reset_host_key_requires_admin(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $this->actingAs(User::factory()->create())->delete('/admin/switches/'.$switch->id.'/host-key')
            ->assertForbidden();

        $this->assertSame(self::HOST_KEY, $switch->refresh()->host_key);
    }

    public function test_test_connection_first_success_pins_host_key(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp']);

        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')->once()
            ->with($switch->hostname, $switch->username, '', Mockery::type('array'), 22, 'commands', $switch->private_key, 'pp', null)
            ->andReturn(new CommandResult(success: true, output: [new CommandOutput('terminal length 0', ''), new CommandOutput('show interface status', '')], hostKey: self::HOST_KEY));
        $this->app->instance(SshProxyClientInterface::class, $proxy);

        $this->actingAs($this->admin())->postJson('/admin/settings/test/switch/'.$switch->id)
            ->assertJson(['success' => true]);

        $this->assertSame(self::HOST_KEY, $switch->refresh()->host_key);
    }

    public function test_test_connection_sends_pinned_key_and_does_not_overwrite_it(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), 22, 'commands', null, null, self::HOST_KEY)
            ->andReturn(new CommandResult(success: true, output: [new CommandOutput('terminal length 0', ''), new CommandOutput('show interface status', '')], hostKey: 'ssh-ed25519 AAAAOTHER'));
        $this->app->instance(SshProxyClientInterface::class, $proxy);

        $this->actingAs($this->admin())->postJson('/admin/settings/test/switch/'.$switch->id)->assertOk();

        $this->assertSame(self::HOST_KEY, $switch->refresh()->host_key);
    }

    public function test_test_connection_surfaces_host_key_mismatch_without_overwriting_pin(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')->once()->andReturn(new CommandResult(
            success: false,
            output: [],
            error: 'SSH connection failed: host key mismatch: expected SHA256:a but server presented SHA256:b',
            hostKey: 'ssh-ed25519 AAAAOTHER',
            errorCode: CommandResult::HOST_KEY_MISMATCH,
        ));
        $this->app->instance(SshProxyClientInterface::class, $proxy);

        $this->actingAs($this->admin())->postJson('/admin/settings/test/switch/'.$switch->id)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'host key mismatch') && str_contains($m, 'reset'));

        $this->assertSame(self::HOST_KEY, $switch->refresh()->host_key);
    }

    public function test_management_test_connection_shows_host_key_mismatch_message(): void
    {
        $switch = SwitchConfig::factory()->create(['host_key' => self::HOST_KEY]);

        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')->andReturn(new CommandResult(
            success: false,
            output: [],
            error: 'boom',
            errorCode: CommandResult::HOST_KEY_MISMATCH,
        ));
        $this->app->instance(SshProxyClientInterface::class, $proxy);

        $this->actingAs($this->admin())->postJson('/admin/switches/'.$switch->id.'/test')
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'host key mismatch'));
    }

    public function test_clear_passphrase_flag_removes_passphrase_and_keeps_key(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey()->create(['passphrase' => 'pp']);

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'private_key' => '',
            'passphrase' => '',
            'clear_passphrase' => true,
        ]))->assertSessionHasNoErrors();

        $switch->refresh();
        $this->assertNull($switch->passphrase);
        $this->assertStringContainsString('fake', (string) $switch->private_key);
    }

    public function test_store_persists_timezone_and_defaults_to_utc(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/switches', $this->payload(['timezone' => 'Europe/London']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Europe/London', SwitchConfig::firstOrFail()->timezone);

        $this->assertSame('UTC', SwitchConfig::factory()->create()->refresh()->timezone);
    }

    public function test_update_changes_timezone(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->actingAs($this->admin())->put('/admin/switches/'.$switch->id, $this->payload([
            'hostname' => $switch->hostname,
            'timezone' => 'Australia/Sydney',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Australia/Sydney', $switch->refresh()->timezone);
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/switches', $this->payload(['timezone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, SwitchConfig::count());
    }
}
