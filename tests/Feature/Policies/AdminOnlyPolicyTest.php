<?php

namespace Tests\Feature\Policies;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Policies\IntegrationConfigPolicy;
use App\Policies\IpAddressPolicy;
use App\Policies\SwitchConfigPolicy;
use App\Policies\UserPolicy;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IpAddressPolicy, UserPolicy, IntegrationConfigPolicy and SwitchConfigPolicy
 * all implement the same rule: every ability is admin-only. One parameterized
 * harness covers all four policies × all seven abilities × both roles.
 */
class AdminOnlyPolicyTest extends TestCase
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

    private static function makeIntegrationConfig(): IntegrationConfig
    {
        $config = new IntegrationConfig;
        $config->integration = 'test-service';
        $config->key = 'test-key';
        $config->value = 'test-value';
        $config->save();

        return $config;
    }

    private static function makeSwitchConfig(): SwitchConfig
    {
        $switchConfig = new SwitchConfig;
        $switchConfig->name = 'Test Switch';
        $switchConfig->hostname = 'switch-'.uniqid();
        $switchConfig->type = 'cisco';
        $switchConfig->username = 'admin';
        $switchConfig->password = 'secret';
        $switchConfig->enabled = true;
        $switchConfig->port = 22;
        $switchConfig->timeout = 30;
        $switchConfig->save();

        return $switchConfig;
    }

    #[DataProvider('policyAbilityProvider')]
    public function test_policy_ability_is_admin_only(string $policyClass, string $ability, ?Closure $subjectFactory, bool $asAdmin, bool $expected): void
    {
        $policy = new $policyClass;
        $actor = $asAdmin ? $this->admin : $this->regularUser;

        $result = $subjectFactory !== null
            ? $policy->{$ability}($actor, $subjectFactory())
            : $policy->{$ability}($actor);

        $this->assertSame($expected, $result);
    }

    public static function policyAbilityProvider(): array
    {
        $ipAddress = fn (): IpAddress => IpAddress::factory()->create();
        $user = fn (): User => User::factory()->create();
        $integrationConfig = fn (): IntegrationConfig => self::makeIntegrationConfig();
        $switchConfig = fn (): SwitchConfig => self::makeSwitchConfig();

        return [
            // IpAddressPolicy
            'IpAddress viewAny (admin)' => [IpAddressPolicy::class, 'viewAny', null, true, true],
            'IpAddress viewAny (non-admin)' => [IpAddressPolicy::class, 'viewAny', null, false, false],
            'IpAddress view (admin)' => [IpAddressPolicy::class, 'view', $ipAddress, true, true],
            'IpAddress view (non-admin)' => [IpAddressPolicy::class, 'view', $ipAddress, false, false],
            'IpAddress create (admin)' => [IpAddressPolicy::class, 'create', null, true, true],
            'IpAddress create (non-admin)' => [IpAddressPolicy::class, 'create', null, false, false],
            'IpAddress update (admin)' => [IpAddressPolicy::class, 'update', $ipAddress, true, true],
            'IpAddress update (non-admin)' => [IpAddressPolicy::class, 'update', $ipAddress, false, false],
            'IpAddress delete (admin)' => [IpAddressPolicy::class, 'delete', $ipAddress, true, true],
            'IpAddress delete (non-admin)' => [IpAddressPolicy::class, 'delete', $ipAddress, false, false],
            'IpAddress restore (admin)' => [IpAddressPolicy::class, 'restore', $ipAddress, true, true],
            'IpAddress restore (non-admin)' => [IpAddressPolicy::class, 'restore', $ipAddress, false, false],
            'IpAddress forceDelete (admin)' => [IpAddressPolicy::class, 'forceDelete', $ipAddress, true, true],
            'IpAddress forceDelete (non-admin)' => [IpAddressPolicy::class, 'forceDelete', $ipAddress, false, false],

            // UserPolicy
            'User viewAny (admin)' => [UserPolicy::class, 'viewAny', null, true, true],
            'User viewAny (non-admin)' => [UserPolicy::class, 'viewAny', null, false, false],
            'User view (admin)' => [UserPolicy::class, 'view', $user, true, true],
            'User view (non-admin)' => [UserPolicy::class, 'view', $user, false, false],
            'User create (admin)' => [UserPolicy::class, 'create', null, true, true],
            'User create (non-admin)' => [UserPolicy::class, 'create', null, false, false],
            'User update (admin)' => [UserPolicy::class, 'update', $user, true, true],
            'User update (non-admin)' => [UserPolicy::class, 'update', $user, false, false],
            'User delete (admin)' => [UserPolicy::class, 'delete', $user, true, true],
            'User delete (non-admin)' => [UserPolicy::class, 'delete', $user, false, false],
            'User restore (admin)' => [UserPolicy::class, 'restore', $user, true, true],
            'User restore (non-admin)' => [UserPolicy::class, 'restore', $user, false, false],
            'User forceDelete (admin)' => [UserPolicy::class, 'forceDelete', $user, true, true],
            'User forceDelete (non-admin)' => [UserPolicy::class, 'forceDelete', $user, false, false],

            // IntegrationConfigPolicy
            'IntegrationConfig viewAny (admin)' => [IntegrationConfigPolicy::class, 'viewAny', null, true, true],
            'IntegrationConfig viewAny (non-admin)' => [IntegrationConfigPolicy::class, 'viewAny', null, false, false],
            'IntegrationConfig view (admin)' => [IntegrationConfigPolicy::class, 'view', $integrationConfig, true, true],
            'IntegrationConfig view (non-admin)' => [IntegrationConfigPolicy::class, 'view', $integrationConfig, false, false],
            'IntegrationConfig create (admin)' => [IntegrationConfigPolicy::class, 'create', null, true, true],
            'IntegrationConfig create (non-admin)' => [IntegrationConfigPolicy::class, 'create', null, false, false],
            'IntegrationConfig update (admin)' => [IntegrationConfigPolicy::class, 'update', $integrationConfig, true, true],
            'IntegrationConfig update (non-admin)' => [IntegrationConfigPolicy::class, 'update', $integrationConfig, false, false],
            'IntegrationConfig delete (admin)' => [IntegrationConfigPolicy::class, 'delete', $integrationConfig, true, true],
            'IntegrationConfig delete (non-admin)' => [IntegrationConfigPolicy::class, 'delete', $integrationConfig, false, false],
            'IntegrationConfig restore (admin)' => [IntegrationConfigPolicy::class, 'restore', $integrationConfig, true, true],
            'IntegrationConfig restore (non-admin)' => [IntegrationConfigPolicy::class, 'restore', $integrationConfig, false, false],
            'IntegrationConfig forceDelete (admin)' => [IntegrationConfigPolicy::class, 'forceDelete', $integrationConfig, true, true],
            'IntegrationConfig forceDelete (non-admin)' => [IntegrationConfigPolicy::class, 'forceDelete', $integrationConfig, false, false],

            // SwitchConfigPolicy
            'SwitchConfig viewAny (admin)' => [SwitchConfigPolicy::class, 'viewAny', null, true, true],
            'SwitchConfig viewAny (non-admin)' => [SwitchConfigPolicy::class, 'viewAny', null, false, false],
            'SwitchConfig view (admin)' => [SwitchConfigPolicy::class, 'view', $switchConfig, true, true],
            'SwitchConfig view (non-admin)' => [SwitchConfigPolicy::class, 'view', $switchConfig, false, false],
            'SwitchConfig create (admin)' => [SwitchConfigPolicy::class, 'create', null, true, true],
            'SwitchConfig create (non-admin)' => [SwitchConfigPolicy::class, 'create', null, false, false],
            'SwitchConfig update (admin)' => [SwitchConfigPolicy::class, 'update', $switchConfig, true, true],
            'SwitchConfig update (non-admin)' => [SwitchConfigPolicy::class, 'update', $switchConfig, false, false],
            'SwitchConfig delete (admin)' => [SwitchConfigPolicy::class, 'delete', $switchConfig, true, true],
            'SwitchConfig delete (non-admin)' => [SwitchConfigPolicy::class, 'delete', $switchConfig, false, false],
            'SwitchConfig restore (admin)' => [SwitchConfigPolicy::class, 'restore', $switchConfig, true, true],
            'SwitchConfig restore (non-admin)' => [SwitchConfigPolicy::class, 'restore', $switchConfig, false, false],
            'SwitchConfig forceDelete (admin)' => [SwitchConfigPolicy::class, 'forceDelete', $switchConfig, true, true],
            'SwitchConfig forceDelete (non-admin)' => [SwitchConfigPolicy::class, 'forceDelete', $switchConfig, false, false],
        ];
    }
}
