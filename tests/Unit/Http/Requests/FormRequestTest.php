<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\IpAddressStoreRequest;
use App\Http\Requests\Ipv6Request;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FormRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ip_address_store_request_authorizes_for_admin(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $request = new IpAddressStoreRequest;
        $request->setUserResolver(fn () => $admin);

        $this->assertTrue($request->authorize());
    }

    public function test_ip_address_store_request_denies_for_non_admin(): void
    {
        $user = User::factory()->create();

        $request = new IpAddressStoreRequest;
        $request->setUserResolver(fn () => $user);

        $this->assertFalse($request->authorize());
    }

    public function test_ip_address_store_request_rules(): void
    {
        $request = new IpAddressStoreRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('address', $rules);
        $this->assertArrayHasKey('comment', $rules);
        $this->assertStringContainsString('ipv4', $rules['address']);
        $this->assertStringContainsString('required', $rules['address']);
    }

    public function test_ipv6_request_authorizes(): void
    {
        $request = new Ipv6Request;
        $this->assertTrue($request->authorize());
    }

    public function test_ipv6_request_rules(): void
    {
        $request = new Ipv6Request;
        $rules = $request->rules();

        $this->assertArrayHasKey('ipv6', $rules);
        $this->assertStringContainsString('ipv6', $rules['ipv6']);
        $this->assertStringContainsString('required', $rules['ipv6']);
    }
}
