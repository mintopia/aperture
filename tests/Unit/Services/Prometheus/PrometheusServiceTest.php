<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Prometheus\PrometheusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrometheusServiceTest extends TestCase
{
    private PrometheusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PrometheusService(
            endpoint: 'https://prometheus.example.com',
            bearerToken: 'test-token',
            verifySsl: true,
            defaultStep: 60,
        );
    }

    public function test_query_sends_promql_to_instant_endpoint(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'resultType' => 'vector',
                    'result' => [
                        ['metric' => ['__name__' => 'up'], 'value' => [1234567890, '1']],
                    ],
                ],
            ]),
        ]);

        $result = $this->service->query('up');

        $this->assertArrayHasKey('resultType', $result);
        $this->assertSame('vector', $result['resultType']);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/query')
                && str_contains($request->url(), 'query=up');
        });
    }

    public function test_query_sends_time_parameter_when_provided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => []])]);

        $this->service->query('up', 1234567890.0);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'time=1234567890');
        });
    }

    public function test_query_range_sends_correct_parameters(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]),
        ]);

        $this->service->queryRange('up', 1000.0, 2000.0, 15);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/api/v1/query_range')
                && str_contains($request->url(), 'query=up')
                && str_contains($request->url(), 'start=1000')
                && str_contains($request->url(), 'end=2000')
                && str_contains($request->url(), 'step=15');
        });
    }

    public function test_query_range_uses_default_step_when_not_provided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => []])]);

        $this->service->queryRange('up', 1000.0, 2000.0);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'step=60');
        });
    }

    public function test_get_port_bandwidth_returns_in_out_time_series(): void
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

        $result = $this->service->getPortBandwidth('switch1', 'Gi0/1', 1000.0, 2000.0);

        $this->assertArrayHasKey('in', $result);
        $this->assertArrayHasKey('out', $result);
        $this->assertCount(2, $result['in']);
        $this->assertSame(1000.0, $result['in'][0]['timestamp']);
        $this->assertSame(1024000.0, $result['in'][0]['value']);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=rate(ifHCInOctets{instance=~"switch1.*",ifName="Gi0/1"}[5m]) * 8 or rate(ifInOctets{instance=~"switch1.*",ifName="Gi0/1"}[5m]) * 8',
            );
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=rate(ifHCOutOctets{instance=~"switch1.*",ifName="Gi0/1"}[5m]) * 8 or rate(ifOutOctets{instance=~"switch1.*",ifName="Gi0/1"}[5m]) * 8',
            );
        });
    }

    public function test_get_port_bandwidth_returns_empty_when_no_data(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]),
        ]);

        $result = $this->service->getPortBandwidth('switch1', 'Gi0/1', 1000.0, 2000.0);

        $this->assertSame([], $result['in']);
        $this->assertSame([], $result['out']);
    }

    public function test_special_characters_in_device_name_are_escaped(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['result' => []]])]);

        $this->service->getPortBandwidth('switch"inject', 'Gi0/1', 1000.0, 2000.0);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'switch\\"inject');
        });
    }

    public function test_handles_malformed_response_gracefully(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix'],
        ])]);

        $result = $this->service->getPortBandwidth('switch1', 'Gi0/1', 1000.0, 2000.0);

        $this->assertSame([], $result['in']);
        $this->assertSame([], $result['out']);
    }

    public function test_get_port_errors_queries_correct_metrics(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]),
        ]);

        $this->service->getPortErrors('switch1', 'Gi0/1', 1000.0, 2000.0);

        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=rate(ifInErrors{instance=~"switch1.*",ifName="Gi0/1"}[5m])',
            );
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=rate(ifOutErrors{instance=~"switch1.*",ifName="Gi0/1"}[5m])',
            );
        });
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return ! str_contains($url, 'ifHCInOctets')
                && ! str_contains($url, 'ifHCOutOctets')
                && ! str_contains($url, 'ifInOctets')
                && ! str_contains($url, 'ifOutOctets');
        });
    }

    public function test_get_device_bandwidth_sums_all_interfaces(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]),
        ]);

        $this->service->getDeviceBandwidth('switch1', 1000.0, 2000.0);

        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=sum(rate(ifHCInOctets{instance=~"switch1.*"}[5m])) * 8 or sum(rate(ifInOctets{instance=~"switch1.*"}[5m])) * 8',
            );
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains(
                urldecode($request->url()),
                'query=sum(rate(ifHCOutOctets{instance=~"switch1.*"}[5m])) * 8 or sum(rate(ifOutOctets{instance=~"switch1.*"}[5m])) * 8',
            );
        });
        Http::assertSentCount(2);
    }

    public function test_sends_bearer_token_when_configured(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => []])]);

        $this->service->query('up');

        Http::assertSent(function (Request $request): bool {
            $auth = $request->header('Authorization');

            return $auth !== [] && $auth[0] === 'Bearer test-token';
        });
    }

    public function test_does_not_send_auth_without_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => []])]);

        $service = new PrometheusService(endpoint: 'https://prom.local');
        $service->query('up');

        Http::assertSent(function (Request $request): bool {
            $auth = $request->header('Authorization');

            return $auth === [];
        });
    }

    public function test_is_available_returns_true_when_configured(): void
    {
        $this->assertTrue($this->service->isAvailable());
    }

    public function test_is_available_returns_false_when_endpoint_empty(): void
    {
        $service = new PrometheusService(endpoint: '');

        $this->assertFalse($service->isAvailable());
    }

    public function test_throws_on_server_error(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $this->expectException(RequestException::class);

        $this->service->query('up');
    }

    public function test_throws_on_connection_failure(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->expectException(ConnectionException::class);

        $this->service->query('up');
    }
}
