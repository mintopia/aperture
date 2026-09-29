<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_index_includes_human_readable_description(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network', metadata: ['source' => 'dhcp']);

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.description', 'Discovered new IP 10.30.0.1 via dhcp')
        );
    }

    public function test_filterable_by_action(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log?action=ip.created');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_process(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?process=scan_network');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_subject_type(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $target = User::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'user.updated', subject: $target, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?subject_type='.urlencode($ip->getMorphClass()));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.subject_type', 'IpAddress')
        );
    }

    #[DataProvider('dateFilterProvider')]
    public function test_filterable_by_date(string $queryParam, string $expectedAction): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();

        $this->travelTo('2026-06-01 12:00:00');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        $this->travelTo('2026-06-05 12:00:00');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');
        $this->travelBack();

        $response = $this->actingAs($admin)->get('/admin/audit-log?'.$queryParam.'=2026-06-03');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', $expectedAction)
        );
    }

    public static function dateFilterProvider(): array
    {
        return [
            'date_from excludes earlier records' => ['date_from', 'ip.updated'],
            'date_to excludes later records' => ['date_to', 'ip.created'],
        ];
    }

    public function test_pagination_works(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        }

        $response = $this->actingAs($admin)->get('/admin/audit-log?perPage=10');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 10)
        );
    }

    public function test_non_admin_cannot_access(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/audit-log')->assertForbidden();
    }

    #[DataProvider('subjectUrlProvider')]
    public function test_subject_url_resolved(string $action, Closure $subjectFactory, string $process, string $expectedType, string $routeName): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $subject = $subjectFactory();
        AuditLog::record(action: $action, subject: $subject, process: $process);

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.subject_type', $expectedType)
            ->where('logs.data.0.subject_url', route($routeName, $subject))
        );
    }

    public static function subjectUrlProvider(): array
    {
        return [
            'user' => ['user.updated', fn () => User::factory()->create(), 'admin', 'User', 'admin.users.show'],
            'ip address' => ['ip.created', fn () => IpAddress::factory()->create(), 'scan_network', 'IpAddress', 'admin.ips.show'],
            'mac address' => ['mac.created', fn () => MacAddress::factory()->create(), 'scan_network', 'MacAddress', 'admin.macs.show'],
            'switch config' => ['switch.updated', fn () => SwitchConfig::factory()->create(), 'admin', 'SwitchConfig', 'admin.switches.show'],
        ];
    }

    public function test_related_url_resolved_when_present(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $target = User::factory()->create();
        AuditLog::record(action: 'ip.assigned', subject: $ip, related: $target, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.related_type', 'User')
            ->where('logs.data.0.related_url', route('admin.users.show', $target))
        );
    }

    public function test_actor_url_resolved_for_admin_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, actor: $admin, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.actor_type', 'User')
            ->where('logs.data.0.actor_url', route('admin.users.show', $admin))
        );
    }

    public function test_urls_are_null_when_no_related_or_actor(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.related_url', null)
            ->where('logs.data.0.actor_url', null)
        );
    }

    #[DataProvider('actionSortDirectionProvider')]
    public function test_sortable_by_action(string $direction, string $firstAction, string $secondAction): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=action&direction='.$direction);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.action', $firstAction)
            ->where('logs.data.1.action', $secondAction)
            ->where('filters.order', 'action')
            ->where('filters.direction', $direction)
        );
    }

    public static function actionSortDirectionProvider(): array
    {
        return [
            'ascending' => ['asc', 'ip.created', 'ip.updated'],
            'descending' => ['desc', 'ip.updated', 'ip.created'],
        ];
    }

    public function test_defaults_to_created_at_desc_sort(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'created_at')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_ignores_invalid_sort_column(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=invalid_column&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'created_at')
            ->where('filters.direction', 'asc')
        );
    }

    public function test_ignores_invalid_sort_direction(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=action&direction=invalid');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'action')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_sortable_by_process(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=process&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('logs.data.0.process', 'admin')
            ->where('logs.data.1.process', 'scan_network')
        );
    }
}
