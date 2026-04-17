<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Prometheus\NullMetricsProvider;
use App\Services\Prometheus\PrometheusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SwitchPortMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    private SwitchConfig $switchConfig;

    private SwitchPort $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAdminUser();
        $this->switchConfig = SwitchConfig::factory()->create([
            'hostname' => 'switch1.example.com',
        ]);
        $this->port = SwitchPort::factory()->for($this->switchConfig)->create([
            'port_name' => 'Gi0/1',
        ]);
    }

    public function test_port_show_includes_metrics_available_flag_when_prometheus_configured(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]),
        ]);

        $this->app->instance(MetricsProviderInterface::class, new PrometheusService(
            endpoint: 'https://prom.test',
            bearerToken: 'test',
        ));

        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.ports.show', [
                'switchConfig' => $this->switchConfig,
                'portId' => 'Gi0/1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('metricsAvailable', true));
    }

    public function test_port_show_includes_metrics_available_false_without_prometheus(): void
    {
        $this->app->instance(MetricsProviderInterface::class, new NullMetricsProvider);

        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.ports.show', [
                'switchConfig' => $this->switchConfig,
                'portId' => 'Gi0/1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('metricsAvailable', false));
    }

    public function test_port_show_includes_bandwidth_data_from_prometheus(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'resultType' => 'matrix',
                    'result' => [
                        [
                            'metric' => ['ifName' => 'Gi0/1'],
                            'values' => [
                                [1000, '1024000'],
                                [1060, '2048000'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->app->instance(MetricsProviderInterface::class, new PrometheusService(
            endpoint: 'https://prom.test',
            bearerToken: 'test',
        ));

        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.ports.show', [
                'switchConfig' => $this->switchConfig,
                'portId' => 'Gi0/1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('bandwidth.in', 2)
            ->has('bandwidth.out', 2)
            ->where('bandwidth.in_bytes', 11520000)
            ->where('bandwidth.out_bytes', 11520000)
            ->where('errors.input', 3072000)
            ->where('errors.output', 3072000)
        );
    }

    public function test_port_show_handles_prometheus_error_gracefully(): void
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->app->instance(MetricsProviderInterface::class, new PrometheusService(
            endpoint: 'https://prom.test',
            bearerToken: 'test',
        ));

        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.ports.show', [
                'switchConfig' => $this->switchConfig,
                'portId' => 'Gi0/1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('bandwidth.in', [])
            ->where('bandwidth.out', [])
        );
    }

    public function test_port_show_passes_errors_data_from_prometheus(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'resultType' => 'matrix',
                    'result' => [
                        [
                            'metric' => ['ifName' => 'Gi0/1'],
                            'values' => [
                                [1000, '5'],
                                [1060, '10'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->app->instance(MetricsProviderInterface::class, new PrometheusService(
            endpoint: 'https://prom.test',
            bearerToken: 'test',
        ));

        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.ports.show', [
                'switchConfig' => $this->switchConfig,
                'portId' => 'Gi0/1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('errors.in_series', 2)
            ->has('errors.out_series', 2)
        );
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'admin')->first();

        if (! $role instanceof Role) {
            $role = new Role;
            $role->code = 'admin';
            $role->name = 'Admin';
            $role->save();
        }

        $user->roles()->attach($role);

        return $user;
    }
}
