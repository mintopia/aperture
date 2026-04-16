<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\BorealisService;
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

    public function test_opnsense_test_records_connection_log(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);

        $this->actingAs($admin)->post('/admin/settings/test/opnsense');

        $this->assertDatabaseHas('connection_test_logs', [
            'integration' => 'opnsense',
            'success' => true,
        ]);
    }

    public function test_failed_connection_records_failure_log(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('Server Error', 500)]);
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);

        $this->actingAs($admin)->post('/admin/settings/test/opnsense');

        $this->assertDatabaseHas('connection_test_logs', [
            'integration' => 'opnsense',
            'success' => false,
        ]);
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
        IntegrationConfig::setValue('pihole', 'password', 'test-pass', true);

        Http::fake([
            'pihole.local/*' => Http::response(['session' => ['sid' => 'abc', 'validity' => 300]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/pihole');

        $response->assertOk();
        $response->assertJson(['success' => true, 'message' => 'Connected and authenticated successfully']);
    }

    public function test_admin_can_test_pihole_connection_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        IntegrationConfig::setValue('pihole', 'endpoint', 'https://pihole.local');
        IntegrationConfig::setValue('pihole', 'password', 'wrong-pass', true);

        Http::fake([
            'pihole.local/*' => Http::response(['error' => ['key' => 'unauthorized']], 401),
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

    public function test_admin_can_test_borealis_connection_success(): void
    {
        $admin = $this->createAdminUser();
        $mock = Mockery::mock(BorealisService::class);
        $mock->shouldReceive('getDeviceCodeRaw')
            ->once()
            ->with('test')
            ->andReturn((object) [
                'device_code' => 'test-code',
                'user_code' => 'TEST-CODE',
                'verification_uri' => 'https://auth.test/verify',
                'expires_in' => 300,
                'interval' => 5,
            ]);
        $this->app->instance(BorealisService::class, $mock);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/borealis');

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_admin_can_test_borealis_connection_failure(): void
    {
        $admin = $this->createAdminUser();
        $mock = Mockery::mock(BorealisService::class);
        $mock->shouldReceive('getDeviceCodeRaw')
            ->once()
            ->andThrow(new \Exception('Connection refused'));
        $this->app->instance(BorealisService::class, $mock);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/borealis');

        $response->assertOk();
        $response->assertJson(['success' => false]);
    }

    public function test_borealis_connection_test_records_log(): void
    {
        $admin = $this->createAdminUser();
        $mock = Mockery::mock(BorealisService::class);
        $mock->shouldReceive('getDeviceCodeRaw')
            ->once()
            ->andReturn((object) ['device_code' => 'x', 'user_code' => 'X', 'verification_uri' => 'https://x', 'expires_in' => 300, 'interval' => 5]);
        $this->app->instance(BorealisService::class, $mock);

        $this->actingAs($admin)->postJson('/admin/settings/test/borealis');

        $this->assertDatabaseHas('connection_test_logs', [
            'integration' => 'borealis',
            'success' => true,
        ]);
    }

    public function test_non_admin_cannot_test_connections(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/admin/settings/test/opnsense');

        $response->assertForbidden();
    }

    public function test_opnsense_test_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://old.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'old-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'old-secret');

        Http::fake([
            'new.example.com/*' => Http::response('ok', 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/opnsense', [
            'endpoint' => 'https://new.example.com',
            'key' => 'new-key',
            'secret' => 'new-secret',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'new.example.com'));
    }

    public function test_librenms_test_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('librenms', 'endpoint', 'https://old-librenms.example.com');
        IntegrationConfig::setValue('librenms', 'api_key', 'old-api-key');

        Http::fake([
            'new-librenms.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/librenms', [
            'endpoint' => 'https://new-librenms.example.com',
            'api_key' => 'new-api-key',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'new-librenms.example.com'));
    }

    public function test_ntopng_test_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://old-ntopng.example.com');

        Http::fake([
            'new-ntopng.example.com/*' => Http::response(['rc' => 0], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/ntopng', [
            'endpoint' => 'https://new-ntopng.example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'new-ntopng.example.com'));
    }

    public function test_pihole_test_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('pihole', 'endpoint', 'https://old-pihole.example.com');
        IntegrationConfig::setValue('pihole', 'password', 'old-pass', true);

        Http::fake([
            'new-pihole.example.com/*' => Http::response(['session' => ['sid' => 'abc', 'validity' => 300]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/pihole', [
            'endpoint' => 'https://new-pihole.example.com',
            'password' => 'new-pass',
            'verify_ssl' => '0',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'new-pihole.example.com'));
    }

    public function test_pihole_test_authenticates_via_api_auth(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            'pihole.test/api/auth' => Http::response(['session' => ['sid' => 'abc', 'validity' => 300]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/pihole', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test-pass',
            'verify_ssl' => '0',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/auth') && $req->method() === 'POST');
    }

    public function test_opnsense_test_falls_back_to_db_when_no_request_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://db-opnsense.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'db-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'db-secret');

        Http::fake([
            'db-opnsense.example.com/*' => Http::response('ok', 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/test/opnsense');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'db-opnsense.example.com'));
    }
}
