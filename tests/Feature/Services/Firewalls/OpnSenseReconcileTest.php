<?php

namespace Tests\Feature\Services\Firewalls;

use App\Models\IpAddress;
use App\Services\Firewalls\OpnSense;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

class OpnSenseReconcileTest extends TestCase
{
    use RefreshDatabase;

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

    // --- reconcileInternet tests ---

    public function test_reconcile_internet_adds_missing_ips(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);

        $service = $this->createServiceWithMockClient([
            // fetchConnectedIps: session/list returns empty
            new Response(200, [], json_encode([])),
            // updateIp for 10.0.0.1 (connect)
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->reconcileInternet();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_internet_removes_extra_ips(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => false]);

        $sessionList = json_encode([
            (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
        ]);

        $service = $this->createServiceWithMockClient([
            // fetchConnectedIps: session/list
            new Response(200, [], $sessionList),
            // removeIp for 10.0.0.1: session/list again + disconnect
            new Response(200, [], $sessionList),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->reconcileInternet();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->removed);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_internet_reports_unchanged(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => false]);

        $sessionList = json_encode([
            (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
        ]);

        $service = $this->createServiceWithMockClient([
            // fetchConnectedIps
            new Response(200, [], $sessionList),
        ]);

        $result = $service->reconcileInternet();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        // 10.0.0.1 is desired-enabled and already connected — unchanged
        $this->assertContains('10.0.0.1', $result->unchanged);
        // 10.0.0.2 is desired-disabled and not connected — no action needed, not tracked
        $this->assertNotContains('10.0.0.2', $result->unchanged);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_internet_dry_run_does_not_apply_changes(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);

        $service = $this->createServiceWithMockClient([
            // fetchConnectedIps: empty
            new Response(200, [], json_encode([])),
        ]);

        $result = $service->reconcileInternet(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_internet_captures_errors(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);

        $service = $this->createServiceWithMockClient([
            // fetchConnectedIps: empty
            new Response(200, [], json_encode([])),
            // updateIp fails
            new ConnectException('Connection refused', new Request('POST', '/test')),
        ]);

        $result = $service->reconcileInternet();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('10.0.0.1', $result->errors[0]);
    }

    // --- reconcileRateLimits tests ---

    public function test_reconcile_rate_limits_adds_missing_limits(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'rate_limit_enabled' => true]);

        $emptyRuleResponse = $this->makeRuleResponse();

        $service = $this->createServiceWithMockClient([
            // fetchRateLimitedIps: get download rule
            new Response(200, [], $emptyRuleResponse),
            // limitIp: get download rule, update, get upload rule, update, reconfigure
            new Response(200, [], $emptyRuleResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], $emptyRuleResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->reconcileRateLimits();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_rate_limits_removes_extra_limits(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'rate_limit_enabled' => false]);

        $ruleWithIp = $this->makeRuleResponse(['10.0.0.1']);

        $service = $this->createServiceWithMockClient([
            // fetchRateLimitedIps: get download rule
            new Response(200, [], $ruleWithIp),
            // unlimitIp: get download rule, update, get upload rule, update, reconfigure
            new Response(200, [], $ruleWithIp),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], $ruleWithIp),
            new Response(200, [], json_encode(['result' => 'saved'])),
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $service->reconcileRateLimits();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->removed);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_rate_limits_reports_unchanged(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'rate_limit_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'rate_limit_enabled' => false]);

        $ruleWithIp = $this->makeRuleResponse(['10.0.0.1']);

        $service = $this->createServiceWithMockClient([
            // fetchRateLimitedIps: get download rule
            new Response(200, [], $ruleWithIp),
        ]);

        $result = $service->reconcileRateLimits();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        // 10.0.0.1 is desired-enabled and already rate-limited — unchanged
        $this->assertContains('10.0.0.1', $result->unchanged);
        // 10.0.0.2 is desired-disabled and not rate-limited — no action needed, not tracked
        $this->assertNotContains('10.0.0.2', $result->unchanged);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_rate_limits_dry_run_does_not_apply_changes(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'rate_limit_enabled' => true]);

        $emptyRuleResponse = $this->makeRuleResponse();

        $service = $this->createServiceWithMockClient([
            // fetchRateLimitedIps: get download rule
            new Response(200, [], $emptyRuleResponse),
        ]);

        $result = $service->reconcileRateLimits(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_rate_limits_captures_errors(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'rate_limit_enabled' => true]);

        $emptyRuleResponse = $this->makeRuleResponse();

        $service = $this->createServiceWithMockClient([
            // fetchRateLimitedIps: get download rule
            new Response(200, [], $emptyRuleResponse),
            // limitIp fails on first call (getShaperRule)
            new ConnectException('Connection refused', new Request('GET', '/test')),
        ]);

        $result = $service->reconcileRateLimits();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.1', $result->added);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('10.0.0.1', $result->errors[0]);
    }

    /**
     * @param  array<int, string>  $ips
     */
    private function makeRuleResponse(array $ips = []): string
    {
        $destination = new stdClass;
        $source = new stdClass;
        foreach ($ips as $ip) {
            $destination->{$ip} = (object) ['value' => $ip, 'selected' => true];
            $source->{$ip} = (object) ['value' => $ip, 'selected' => true];
        }

        return json_encode([
            'rule' => (object) [
                'description' => 'Rate limit',
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
                'destination' => $destination,
                'source' => $source,
            ],
        ]);
    }
}
