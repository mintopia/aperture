<?php

namespace Tests\Feature\Policies;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Policies\IpAddressPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IpAddressPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $regularUser;

    private IpAddressPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new IpAddressPolicy;

        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
    }

    public function test_admin_can_view_any_ip_addresses(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_non_admin_cannot_view_any_ip_addresses(): void
    {
        $this->assertFalse($this->policy->viewAny($this->regularUser));
    }

    public function test_admin_can_view_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertTrue($this->policy->view($this->admin, $ip));
    }

    public function test_non_admin_cannot_view_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertFalse($this->policy->view($this->regularUser, $ip));
    }

    public function test_admin_can_create_ip_addresses(): void
    {
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_non_admin_cannot_create_ip_addresses(): void
    {
        $this->assertFalse($this->policy->create($this->regularUser));
    }

    public function test_admin_can_update_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertTrue($this->policy->update($this->admin, $ip));
    }

    public function test_non_admin_cannot_update_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertFalse($this->policy->update($this->regularUser, $ip));
    }

    public function test_admin_can_delete_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertTrue($this->policy->delete($this->admin, $ip));
    }

    public function test_non_admin_cannot_delete_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertFalse($this->policy->delete($this->regularUser, $ip));
    }

    public function test_admin_can_restore_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertTrue($this->policy->restore($this->admin, $ip));
    }

    public function test_non_admin_cannot_restore_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertFalse($this->policy->restore($this->regularUser, $ip));
    }

    public function test_admin_can_force_delete_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertTrue($this->policy->forceDelete($this->admin, $ip));
    }

    public function test_non_admin_cannot_force_delete_an_ip_address(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertFalse($this->policy->forceDelete($this->regularUser, $ip));
    }
}
