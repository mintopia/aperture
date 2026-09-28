<?php

namespace Tests\Feature\Console;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\TestCase;

class ResetCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['opnsense.test/*' => fn (Request $request) => Http::response($this->opnsenseBody($request->url()))]);

        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.test');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'verify_ssl', '0');
        IntegrationConfig::setValue('opnsense', 'zone_id', '1');
        IntegrationConfig::setValue('opnsense', 'ratelimit_up_uuid', 'up-uuid');
        IntegrationConfig::setValue('opnsense', 'ratelimit_down_uuid', 'down-uuid');
    }

    /**
     * @return array<string, mixed>
     */
    private function opnsenseBody(string $url): array
    {
        return match (true) {
            str_contains($url, 'session/list') => [],
            str_contains($url, 'trafficshaper/settings/get_rule') => ['rule' => [
                'description' => 'test',
                'destination_not' => '0',
                'direction' => new stdClass,
                'dscp' => new stdClass,
                'dst_port' => '',
                'enabled' => '1',
                'interface' => new stdClass,
                'interface2' => new stdClass,
                'iplen' => '',
                'proto' => new stdClass,
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => '',
                'target' => new stdClass,
                'destination' => new stdClass,
                'source' => new stdClass,
            ]],
            str_contains($url, 'trafficshaper/settings/set_rule') => ['result' => 'saved'],
            default => ['status' => 'ok'],
        };
    }

    public function test_command_exits_when_not_confirmed(): void
    {
        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'no')
            ->assertSuccessful();
    }

    public function test_command_deletes_non_admin_users_and_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->rate_limit_enabled = false;
        $ip->save();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
    }

    public function test_command_unlimits_limited_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->rate_limit_enabled = true;
        $ip->save();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.2']);
    }

    public function test_command_deletes_non_admin_users_when_no_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
    }
}
