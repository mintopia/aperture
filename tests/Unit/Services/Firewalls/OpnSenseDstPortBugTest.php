<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Firewalls;

use App\Services\Firewalls\OpnSense;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

class OpnSenseDstPortBugTest extends TestCase
{
    public function test_update_shaper_rule_sends_dst_port_not_src_port(): void
    {
        $ruleResponse = json_encode([
            'rule' => (object) [
                'description' => 'Test rule',
                'destination_not' => '0',
                'direction' => (object) ['in' => (object) ['value' => 'in', 'selected' => true]],
                'dscp' => (object) [],
                'dst_port' => '8080',
                'enabled' => '1',
                'interface' => (object) ['wan' => (object) ['value' => 'wan', 'selected' => true]],
                'interface2' => (object) [],
                'iplen' => '',
                'proto' => (object) [],
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => '443',
                'target' => (object) ['pipe1' => (object) ['value' => 'pipe1', 'selected' => true]],
                'destination' => (object) [],
                'source' => (object) [],
            ],
        ]);

        $history = [];
        $mock = new MockHandler([
            new Response(200, [], $ruleResponse),
            new Response(200, [], json_encode(['result' => 'saved'])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        $service = new OpnSense(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            zoneId: 1,
            verify: false,
            uploadRuleUuid: 'up-uuid',
            downloadRuleUuid: 'down-uuid',
        );

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        // Call addHostToRule which triggers getShaperRule + updateShaperRule
        $method = $reflection->getMethod('addHostToRule');
        $method->invoke($service, 'down-uuid', '10.0.0.1', 'destination');

        // The second request is the set_rule POST — inspect its payload
        $this->assertCount(2, $history);
        $setRuleBody = json_decode((string) $history[1]['request']->getBody(), false);

        // dst_port must be '8080' (the actual dst_port), NOT '443' (the src_port)
        $this->assertEquals('8080', $setRuleBody->rule->dst_port, 'dst_port should use the rule dst_port value, not src_port');
        $this->assertEquals('443', $setRuleBody->rule->src_port, 'src_port should remain unchanged');
    }
}
