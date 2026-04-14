<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\SshProxyClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TestConnectionControllerTest extends TestCase
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

    public function test_admin_can_test_opnsense_connection_success(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'testkey', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'testsecret', true);

        Http::fake([
            'opnsense.local/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/opnsense');

        $response->assertOk();
        $response->assertJson(['success' => true, 'message' => 'Connected successfully']);
    }

    public function test_admin_can_test_opnsense_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        Http::fake([
            'opnsense.local/*' => Http::response('Server Error', 500),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/opnsense');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_admin_can_test_librenms_connection_success(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('librenms', 'endpoint', 'https://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-api-key', true);

        Http::fake([
            'librenms.local/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/librenms');

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_admin_can_test_librenms_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('librenms', 'endpoint', 'https://librenms.local');

        Http::fake([
            'librenms.local/*' => Http::response('Not Found', 404),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/librenms');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_admin_can_test_ntopng_connection_success(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://ntopng.local');

        Http::fake([
            'ntopng.local/*' => Http::response(['rc' => 0], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/ntopng');

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_admin_can_test_ntopng_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://ntopng.local');

        Http::fake([
            'ntopng.local/*' => Http::response('Unauthorized', 401),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/ntopng');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_admin_can_test_pihole_connection_success(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('pihole', 'endpoint', 'https://pihole.local');

        Http::fake([
            'pihole.local/*' => Http::response(['status' => 'enabled'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/pihole');

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_admin_can_test_pihole_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('pihole', 'endpoint', 'https://pihole.local');

        Http::fake([
            'pihole.local/*' => Http::response('Bad Gateway', 502),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/pihole');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_admin_can_test_switch_connection_success(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $mockProxy = Mockery::mock(SshProxyClientInterface::class);
        $mockProxy->shouldReceive('execute')
            ->once()
            ->with($switch->hostname, $switch->username, $switch->password, Mockery::type('array'))
            ->andReturn(new CommandResult(success: true, output: []));

        $this->app->instance(SshProxyClientInterface::class, $mockProxy);

        $response = $this->actingAs($admin)->postJson(
            '/admin/settings/test/switch/'.$switch->id
        );

        $response->assertOk();
        $response->assertJson(['success' => true, 'message' => 'Connected successfully']);
    }

    public function test_admin_can_test_switch_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $mockProxy = Mockery::mock(SshProxyClientInterface::class);
        $mockProxy->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->app->instance(SshProxyClientInterface::class, $mockProxy);

        $response = $this->actingAs($admin)->postJson(
            '/admin/settings/test/switch/'.$switch->id
        );

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_non_admin_cannot_test_connections(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/admin/settings/test/opnsense');

        $response->assertForbidden();
    }
}
