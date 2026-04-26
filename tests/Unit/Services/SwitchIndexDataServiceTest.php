<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchSyncRun;
use App\Services\SwitchIndexDataService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SwitchIndexDataServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SwitchIndexDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->service = new SwitchIndexDataService;
    }

    public function test_assemble_returns_expected_keys(): void
    {
        $request = Request::create('/admin/switches', 'GET');

        $result = $this->service->assemble($request);

        $this->assertArrayHasKey('switches', $result);
        $this->assertArrayHasKey('filters', $result);
    }

    public function test_assemble_returns_switches_with_port_counts(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'connected',
        ]);
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'down',
        ]);

        $request = Request::create('/admin/switches', 'GET');

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $switch = $result['switches']->first();
        $this->assertSame(2, $switch['port_count']);
        $this->assertSame(1, $switch['ports_up']);
        $this->assertSame(1, $switch['ports_down']);
        $this->assertSame(0, $switch['ports_error']);
    }

    public function test_assemble_default_sort_order(): void
    {
        SwitchConfig::factory()->create(['name' => 'Bravo']);
        SwitchConfig::factory()->create(['name' => 'Alpha']);

        $request = Request::create('/admin/switches', 'GET');

        $result = $this->service->assemble($request);

        $this->assertSame('Alpha', $result['switches']->first()['name']);
    }

    public function test_assemble_custom_sort_order(): void
    {
        SwitchConfig::factory()->create(['name' => 'Alpha', 'hostname' => 'alpha.example.com']);
        SwitchConfig::factory()->create(['name' => 'Bravo', 'hostname' => 'bravo.example.com']);

        $request = Request::create('/admin/switches', 'GET', [
            'order' => 'hostname',
            'direction' => 'desc',
        ]);

        $result = $this->service->assemble($request);

        $this->assertSame('bravo.example.com', $result['switches']->first()['hostname']);
    }

    public function test_assemble_ignores_invalid_sort_column(): void
    {
        SwitchConfig::factory()->create(['name' => 'Alpha']);
        SwitchConfig::factory()->create(['name' => 'Bravo']);

        $request = Request::create('/admin/switches', 'GET', [
            'order' => 'invalid_column',
        ]);

        $result = $this->service->assemble($request);

        $this->assertSame('name', $result['filters']->order);
    }

    public function test_assemble_ignores_invalid_direction(): void
    {
        $request = Request::create('/admin/switches', 'GET', [
            'direction' => 'sideways',
        ]);

        $result = $this->service->assemble($request);

        $this->assertSame('asc', $result['filters']->direction);
    }

    public function test_assemble_filters_by_search(): void
    {
        SwitchConfig::factory()->create(['name' => 'Core Switch', 'hostname' => 'core.example.com']);
        SwitchConfig::factory()->create(['name' => 'Edge Switch', 'hostname' => 'edge.example.com']);

        $request = Request::create('/admin/switches', 'GET', ['search' => 'Core']);

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $this->assertSame('Core Switch', $result['switches']->first()['name']);
    }

    public function test_assemble_filters_by_hostname_search(): void
    {
        SwitchConfig::factory()->create(['name' => 'Switch A', 'hostname' => 'core.example.com']);
        SwitchConfig::factory()->create(['name' => 'Switch B', 'hostname' => 'edge.example.com']);

        $request = Request::create('/admin/switches', 'GET', ['search' => 'edge']);

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $this->assertSame('Switch B', $result['switches']->first()['name']);
    }

    public function test_assemble_filters_by_enabled_status(): void
    {
        SwitchConfig::factory()->create(['name' => 'Active', 'enabled' => true]);
        SwitchConfig::factory()->create(['name' => 'Inactive', 'enabled' => false]);

        $request = Request::create('/admin/switches', 'GET', ['status' => 'enabled']);

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $this->assertSame('Active', $result['switches']->first()['name']);
    }

    public function test_assemble_filters_by_disabled_status(): void
    {
        SwitchConfig::factory()->create(['name' => 'Active', 'enabled' => true]);
        SwitchConfig::factory()->create(['name' => 'Inactive', 'enabled' => false]);

        $request = Request::create('/admin/switches', 'GET', ['status' => 'disabled']);

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $this->assertSame('Inactive', $result['switches']->first()['name']);
    }

    public function test_assemble_filters_by_type(): void
    {
        SwitchConfig::factory()->create(['name' => 'Cisco Switch', 'type' => 'cisco']);
        SwitchConfig::factory()->create(['name' => 'Other Switch', 'type' => 'juniper']);

        $request = Request::create('/admin/switches', 'GET', ['type' => 'cisco']);

        $result = $this->service->assemble($request);

        $this->assertCount(1, $result['switches']);
        $this->assertSame('Cisco Switch', $result['switches']->first()['name']);
    }

    public function test_assemble_includes_sync_information(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        $request = Request::create('/admin/switches', 'GET');

        $result = $this->service->assemble($request);

        $switch = $result['switches']->first();
        $this->assertArrayHasKey('last_synced_at', $switch);
        $this->assertArrayHasKey('latest_sync_status', $switch);
        $this->assertSame('completed', $switch['latest_sync_status']);
    }

    public function test_assemble_filters_preserve_in_response(): void
    {
        $request = Request::create('/admin/switches', 'GET', [
            'search' => 'test',
            'status' => 'enabled',
            'type' => 'cisco',
            'order' => 'hostname',
            'direction' => 'desc',
        ]);

        $result = $this->service->assemble($request);

        $this->assertSame('test', $result['filters']->search);
        $this->assertSame('enabled', $result['filters']->status);
        $this->assertSame('cisco', $result['filters']->type);
        $this->assertSame('hostname', $result['filters']->order);
        $this->assertSame('desc', $result['filters']->direction);
    }

    public function test_assemble_switch_has_resource_fields(): void
    {
        SwitchConfig::factory()->create([
            'name' => 'TestSwitch',
            'hostname' => 'test.example.com',
            'type' => 'cisco',
            'enabled' => true,
        ]);

        $request = Request::create('/admin/switches', 'GET');

        $result = $this->service->assemble($request);

        $switch = $result['switches']->first();
        $this->assertArrayHasKey('id', $switch);
        $this->assertArrayHasKey('name', $switch);
        $this->assertArrayHasKey('hostname', $switch);
        $this->assertArrayHasKey('type', $switch);
        $this->assertArrayHasKey('enabled', $switch);
    }

    public function test_assemble_empty_search_returns_all(): void
    {
        SwitchConfig::factory()->count(3)->create();

        $request = Request::create('/admin/switches', 'GET', ['search' => '']);

        $result = $this->service->assemble($request);

        $this->assertCount(3, $result['switches']);
    }

    public function test_assemble_invalid_status_returns_all(): void
    {
        SwitchConfig::factory()->create(['enabled' => true]);
        SwitchConfig::factory()->create(['enabled' => false]);

        $request = Request::create('/admin/switches', 'GET', ['status' => 'invalid']);

        $result = $this->service->assemble($request);

        $this->assertCount(2, $result['switches']);
    }
}
