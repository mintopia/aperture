<?php

namespace Tests\Unit\Services\Firewalls;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Firewalls\OpnSense;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

class OpnSenseTest extends TestCase
{
    protected function createServiceWithMockClient(array $responses): OpnSense
    {
        $service = new OpnSense(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            zoneId: 1,
            verify: false,
            uploadRuleUuid: 'up-uuid',
            downloadRuleUuid: 'down-uuid',
        );

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        return $service;
    }

    public function test_update_ip_makes_post_request(): void
    {
        $service = $this->createServiceWithMockClient([
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->updateIp('10.0.0.1', 'Test User');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_get_uptime_returns_seconds(): void
    {
        $uptimeTime = now()->subMinutes(30)->toIso8601String();
        $service = $this->createServiceWithMockClient([
            new Response(200, [], json_encode(['uptime' => $uptimeTime])),
        ]);

        $result = $service->getUptime();
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_remove_ip_disconnects_session(): void
    {
        $sessionList = json_encode([
            (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
            (object) ['sessionId' => 'sess-2', 'ipAddress' => '10.0.0.2'],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $sessionList),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->removeIp('10.0.0.1');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_remove_ip_handles_no_matching_session(): void
    {
        $sessionList = json_encode([
            (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.2'],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $sessionList),
        ]);

        $result = $service->removeIp('10.0.0.99');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_limit_ip_adds_host_to_rules(): void
    {
        $ruleResponse = json_encode([
            'rule' => (object) [
                'description' => 'Download limit',
                'destination_not' => '0',
                'direction' => (object) ['in' => (object) ['value' => 'in', 'selected' => true]],
                'dscp' => (object) [],
                'dst_port' => '',
                'enabled' => '1',
                'interface' => (object) ['wan' => (object) ['value' => 'wan', 'selected' => true]],
                'interface2' => (object) [],
                'iplen' => '',
                'proto' => (object) [],
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => '',
                'target' => (object) ['pipe1' => (object) ['value' => 'pipe1', 'selected' => true]],
                'destination' => (object) [],
                'source' => (object) [],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $ruleResponse), // getShaperRule for download
            new Response(200, [], json_encode(['result' => 'saved'])), // updateShaperRule
            new Response(200, [], $ruleResponse), // getShaperRule for upload
            new Response(200, [], json_encode(['result' => 'saved'])), // updateShaperRule
            new Response(200, [], json_encode(['status' => 'ok'])), // applyShaperRules
        ]);

        $result = $service->limitIp('10.0.0.1');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_unlimit_ip_removes_host_from_rules(): void
    {
        $ruleResponse = json_encode([
            'rule' => (object) [
                'description' => 'Download limit',
                'destination_not' => '0',
                'direction' => (object) ['in' => (object) ['value' => 'in', 'selected' => true]],
                'dscp' => (object) [],
                'dst_port' => '',
                'enabled' => '1',
                'interface' => (object) ['wan' => (object) ['value' => 'wan', 'selected' => true]],
                'interface2' => (object) [],
                'iplen' => '',
                'proto' => (object) [],
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => '',
                'target' => (object) ['pipe1' => (object) ['value' => 'pipe1', 'selected' => true]],
                'destination' => (object) ['10.0.0.1' => (object) ['value' => '10.0.0.1', 'selected' => true]],
                'source' => (object) ['10.0.0.1' => (object) ['value' => '10.0.0.1', 'selected' => true]],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $ruleResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], $ruleResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->unlimitIp('10.0.0.1');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_get_throws_backend_exception_on_guzzle_error(): void
    {
        $service = $this->createServiceWithMockClient([
            new ConnectException(
                'Connection refused',
                new Request('GET', '/test')
            ),
        ]);

        $this->expectException(BackendException::class);
        $service->getUptime();
    }

    public function test_update_shaper_rule_throws_on_failed_save(): void
    {
        $ruleResponse = json_encode([
            'rule' => (object) [
                'description' => 'test',
                'destination_not' => '0',
                'direction' => (object) [],
                'dscp' => (object) [],
                'dst_port' => '',
                'enabled' => '1',
                'interface' => (object) [],
                'interface2' => (object) [],
                'iplen' => '',
                'proto' => (object) [],
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => '',
                'target' => (object) [],
                'destination' => (object) [],
                'source' => (object) [],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $ruleResponse),
            new Response(200, [], json_encode(['result' => 'failed'])), // Not 'saved'
        ]);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Unable to update shaper rule');

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('addHostToRule');
        $method->invoke($service, 'down-uuid', '10.0.0.1', 'destination');
    }

    public function test_decode_response_throws_on_invalid_json(): void
    {
        $service = $this->createServiceWithMockClient([
            new Response(200, [], 'not-json{'),
        ]);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Unable to decode response');
        $service->getUptime();
    }

    public function test_add_allowed_hostnames(): void
    {
        $settingsResponse = json_encode([
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [
                                '1.1.1.1' => (object) ['value' => '1.1.1.1'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $settingsResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
        ]);

        // Use localhost which should resolve
        $result = $service->addAllowedHostnames(['localhost']);
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_add_allowed_hostnames_throws_on_malformed_response(): void
    {
        $settingsResponse = json_encode([
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => 'not-an-object',
                ],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $settingsResponse),
        ]);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Response is malformed');
        $service->addAllowedHostnames(['example.com']);
    }

    public function test_remove_ip_skips_non_object_sessions(): void
    {
        $sessionList = json_encode([
            'some-string-entry',
            42,
            (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $sessionList),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->removeIp('10.0.0.1');
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_add_allowed_hostnames_skips_non_matching_zone(): void
    {
        $settingsResponse = json_encode([
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 99,
                        ],
                        'uuid-2' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $settingsResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
        ]);

        $result = $service->addAllowedHostnames(['localhost']);
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_add_allowed_hostnames_skips_non_object_zones(): void
    {
        $settingsResponse = json_encode([
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => 'not-an-object',
                        'uuid-2' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $settingsResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
        ]);

        $result = $service->addAllowedHostnames(['localhost']);
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_add_allowed_hostnames_skips_unresolvable_hostnames(): void
    {
        $settingsResponse = json_encode([
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $settingsResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
        ]);

        $result = $service->addAllowedHostnames(['this-hostname-definitely-does-not-exist-xyz123.invalid']);
        $this->assertInstanceOf(OpnSense::class, $result);
    }

    public function test_post_throws_backend_exception_on_guzzle_error(): void
    {
        $service = $this->createServiceWithMockClient([
            new ConnectException(
                'Connection refused',
                new Request('POST', '/test')
            ),
        ]);

        $this->expectException(BackendException::class);
        $service->updateIp('10.0.0.1', 'Test User');
    }

    public function test_reconcile_adds_desired_ips_not_in_current(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        $enableCalled = [];
        $disableCalled = [];

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            ['10.0.0.1'],
            ['10.0.0.1', '10.0.0.2'],
            function (string $ip) use (&$enableCalled): void {
                $enableCalled[] = $ip;
            },
            function (string $ip) use (&$disableCalled): void {
                $disableCalled[] = $ip;
            },
            false,
        );

        $this->assertSame(['10.0.0.2'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);
        $this->assertSame(['10.0.0.2'], $enableCalled);
        $this->assertSame([], $disableCalled);
    }

    public function test_reconcile_removes_current_ips_not_in_desired(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        $enableCalled = [];
        $disableCalled = [];

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            ['10.0.0.1', '10.0.0.2'],
            ['10.0.0.1'],
            function (string $ip) use (&$enableCalled): void {
                $enableCalled[] = $ip;
            },
            function (string $ip) use (&$disableCalled): void {
                $disableCalled[] = $ip;
            },
            false,
        );

        $this->assertSame([], $result->added);
        $this->assertSame(['10.0.0.2'], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $enableCalled);
        $this->assertSame(['10.0.0.2'], $disableCalled);
    }

    public function test_reconcile_skips_actions_on_dry_run(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        $enableCalled = [];
        $disableCalled = [];

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            ['10.0.0.1'],
            ['10.0.0.2'],
            function (string $ip) use (&$enableCalled): void {
                $enableCalled[] = $ip;
            },
            function (string $ip) use (&$disableCalled): void {
                $disableCalled[] = $ip;
            },
            true,
        );

        $this->assertSame(['10.0.0.2'], $result->added);
        $this->assertSame(['10.0.0.1'], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $enableCalled);
        $this->assertSame([], $disableCalled);
    }

    public function test_reconcile_captures_enable_action_errors_per_ip(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            [],
            ['10.0.0.1', '10.0.0.2'],
            function (string $ip): void {
                throw new \RuntimeException('enable failed');
            },
            function (string $ip): void {},
            false,
        );

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('10.0.0.1: enable failed', $result->errors[0]);
        $this->assertStringContainsString('10.0.0.2: enable failed', $result->errors[1]);
    }

    public function test_reconcile_captures_disable_action_errors_per_ip(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            ['10.0.0.1', '10.0.0.2'],
            [],
            function (string $ip): void {},
            function (string $ip): void {
                throw new \RuntimeException('disable failed');
            },
            false,
        );

        $this->assertSame([], $result->added);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->removed);
        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('10.0.0.1: disable failed', $result->errors[0]);
        $this->assertStringContainsString('10.0.0.2: disable failed', $result->errors[1]);
    }

    public function test_reconcile_handles_empty_sets(): void
    {
        $service = $this->createServiceWithMockClient([]);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('reconcile');

        /** @var ReconcileResult $result */
        $result = $method->invoke(
            $service,
            [],
            [],
            function (string $ip): void {},
            function (string $ip): void {},
            false,
        );

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }
}
