<?php

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\StoreSwitchRequest;
use App\Http\Requests\Admin\UpdateSwitchRequest;
use App\Models\SwitchConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

class SwitchRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_switch_request_authorizes(): void
    {
        $this->assertTrue((new StoreSwitchRequest)->authorize());
    }

    public function test_store_switch_request_rules(): void
    {
        $request = new StoreSwitchRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('hostname', $rules);
        $this->assertArrayHasKey('type', $rules);
        $this->assertArrayHasKey('username', $rules);
        $this->assertArrayHasKey('password', $rules);
    }

    public function test_update_switch_request_authorizes(): void
    {
        $this->assertTrue((new UpdateSwitchRequest)->authorize());
    }

    public function test_update_switch_request_rules(): void
    {
        $switchConfig = new SwitchConfig;
        $switchConfig->name = 'Test Switch';
        $switchConfig->hostname = 'switch-test-'.uniqid();
        $switchConfig->type = 'cisco';
        $switchConfig->username = 'admin';
        $switchConfig->password = 'secret';
        $switchConfig->enabled = true;
        $switchConfig->port = 22;
        $switchConfig->timeout = 30;
        $switchConfig->save();

        $request = new UpdateSwitchRequest;
        $route = new Route('PUT', '/admin/switches/{switchConfig}', []);
        $route->bind($request);
        $route->setParameter('switchConfig', $switchConfig);

        $request->setRouteResolver(fn (): Route => $route);

        $rules = $request->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('hostname', $rules);
        $this->assertArrayHasKey('type', $rules);
    }
}
