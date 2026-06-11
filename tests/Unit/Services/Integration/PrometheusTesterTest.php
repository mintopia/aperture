<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\PrometheusTester;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrometheusTesterTest extends TestCase
{
    private PrometheusTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new PrometheusTester;
    }

    /**
     * Fake the buildinfo and instant-query endpoints independently.
     *
     * Accepts loosely-typed entries so tests can exercise malformed series.
     *
     * @param  list<mixed>|null  $queryResult
     */
    private function fakePrometheus(
        ?array $queryResult = null,
        int $queryStatus = 200,
        int $buildinfoStatus = 200,
    ): void {
        Http::fake([
            '*/api/v1/status/buildinfo' => Http::response(
                ['status' => 'success', 'data' => ['version' => '2.50.0']],
                $buildinfoStatus,
            ),
            '*/api/v1/query*' => Http::response(
                [
                    'status' => 'success',
                    'data' => [
                        'resultType' => 'vector',
                        'result' => $queryResult ?? [],
                    ],
                ],
                $queryStatus,
            ),
        ]);
    }

    /**
     * A single vector series whose newest sample has the given unix timestamp.
     *
     * @return list<array{metric: array<string, string>, value: array{0: int, 1: string}}>
     */
    private function seriesAt(int $timestamp): array
    {
        return [
            [
                'metric' => ['__name__' => 'ntopng_host_bytes_rcvd', 'host' => '10.0.0.1'],
                'value' => [$timestamp, '123456'],
            ],
        ];
    }

    public function test_returns_success_on_200_response(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 60));

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bearer_token' => 'bad-token',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_bearer_token_when_provided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bearer_token' => 'my-secret-token',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && $auth[0] === 'Bearer my-secret-token';
        });
    }

    public function test_does_not_send_auth_header_without_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth) || $auth[0] === '';
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://prometheus.local:9090/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://prometheus.local:9090/api/v1/status/buildinfo'));
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-prom.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-prom.example.com'));
    }

    public function test_handles_connection_timeout(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_runs_instant_query_for_default_bandwidth_metric_after_buildinfo(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 60));

        $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query')
            && str_contains(urldecode($request->url()), 'ntopng_host_bytes_rcvd'));
    }

    public function test_succeeds_when_buildinfo_ok_and_metric_has_recent_samples(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 120));

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertTrue($result->success);
    }

    public function test_fails_when_metric_has_no_series(): void
    {
        $this->fakePrometheus(queryResult: []);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertFalse($result->success);
        $this->assertMatchesRegularExpression('/no (recent )?(samples|data)/i', $result->message);
        $this->assertStringContainsString('ntopng_host_bytes_rcvd', $result->message);
    }

    public function test_fails_when_newest_sample_is_older_than_fifteen_minutes(): void
    {
        // Newest sample is an hour old — well past the 15-minute staleness threshold.
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 3600));

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertFalse($result->success);
        $this->assertMatchesRegularExpression('/stale|age|old|minute/i', $result->message);
    }

    public function test_uses_newest_sample_across_series_for_staleness(): void
    {
        // One dead series and one fresh series: freshest sample wins.
        $this->fakePrometheus(queryResult: [
            [
                'metric' => ['host' => '10.0.0.1'],
                'value' => [time() - 86400, '1'],
            ],
            [
                'metric' => ['host' => '10.0.0.2'],
                'value' => [time() - 30, '2'],
            ],
        ]);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertTrue($result->success);
    }

    public function test_skips_malformed_series_entries_when_a_valid_fresh_sample_exists(): void
    {
        // Malformed entries are skipped, not fatal: the valid fresh sample wins.
        $this->fakePrometheus(queryResult: [
            'not-an-array-sample',
            [
                'metric' => ['host' => '10.0.0.1'],
            ],
            [
                'metric' => ['host' => '10.0.0.2'],
                'value' => 'not-an-array-value',
            ],
            [
                'metric' => ['host' => '10.0.0.3'],
                'value' => ['not-a-numeric-timestamp', '123'],
            ],
            [
                'metric' => ['host' => '10.0.0.4'],
                'value' => [time() - 30, '456'],
            ],
        ]);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertTrue($result->success);
    }

    public function test_fails_when_all_series_entries_are_malformed(): void
    {
        // Entries missing a usable value/timestamp yield no samples at all.
        $this->fakePrometheus(queryResult: [
            'not-an-array-sample',
            [
                'metric' => ['host' => '10.0.0.1'],
                'value' => 'not-an-array-value',
            ],
            [
                'metric' => ['host' => '10.0.0.2'],
                'value' => ['not-a-numeric-timestamp', '123'],
            ],
        ]);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertFalse($result->success);
        $this->assertMatchesRegularExpression('/no (recent )?(samples|data)/i', $result->message);
    }

    public function test_fails_when_query_endpoint_returns_server_error(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time()), queryStatus: 500);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertFalse($result->success);
    }

    public function test_fails_when_buildinfo_fails_even_if_metric_data_is_fresh(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time()), buildinfoStatus: 500);

        $result = $this->tester->connect(['endpoint' => 'https://prometheus.local:9090']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_metric_name_override_from_config_is_used_in_query(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 60));

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bandwidth_rcvd_metric' => 'custom_metric',
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query')
            && str_contains(urldecode($request->url()), 'custom_metric'));
    }

    public function test_sends_bearer_token_on_instant_query_request(): void
    {
        $this->fakePrometheus(queryResult: $this->seriesAt(time() - 60));

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bearer_token' => 'my-secret-token',
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/api/v1/query')) {
                return false;
            }

            $auth = $request->header('Authorization');

            return ! empty($auth) && $auth[0] === 'Bearer my-secret-token';
        });
    }
}
