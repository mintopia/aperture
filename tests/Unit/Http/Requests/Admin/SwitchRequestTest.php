<?php

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\StoreSwitchRequest;
use App\Http\Requests\Admin\UpdateSwitchRequest;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

class SwitchRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
    }

    public function test_store_switch_request_authorizes_for_admin(): void
    {
        $request = new StoreSwitchRequest;
        $request->setUserResolver(fn () => $this->admin);

        $this->assertTrue($request->authorize());
    }

    public function test_store_switch_request_denies_for_non_admin(): void
    {
        $request = new StoreSwitchRequest;
        $request->setUserResolver(fn () => $this->regularUser);

        $this->assertFalse($request->authorize());
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

    public function test_update_switch_request_authorizes_for_admin(): void
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
        $request->setUserResolver(fn () => $this->admin);
        $route = new Route('PUT', '/admin/switches/{switchConfig}', []);
        $route->bind($request);
        $route->setParameter('switchConfig', $switchConfig);
        $request->setRouteResolver(fn () => $route);

        $this->assertTrue($request->authorize());
    }

    public function test_update_switch_request_denies_for_non_admin(): void
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
        $request->setUserResolver(fn () => $this->regularUser);
        $route = new Route('PUT', '/admin/switches/{switchConfig}', []);
        $route->bind($request);
        $route->setParameter('switchConfig', $switchConfig);
        $request->setRouteResolver(fn () => $route);

        $this->assertFalse($request->authorize());
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
        $request->setRouteResolver(fn () => $route);

        $rules = $request->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('hostname', $rules);
        $this->assertArrayHasKey('type', $rules);
    }
}
