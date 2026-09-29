<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpnSense;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\OpnSense\OpnSenseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class OpnSenseClientTest extends TestCase
{
    private function client(): OpnSenseClient
    {
        return OpnSenseClient::fromConfig([
            'endpoint' => 'http://opnsense.local/',
            'key' => 'k',
            'secret' => 's',
            'verify_ssl' => false,
        ]);
    }

    public function test_get_returns_decoded_json(): void
    {
        Http::fake(['*' => Http::response('{"status":"ok"}')]);

        $result = $this->client()->get('/api/test', ['a' => 'b']);

        $this->assertSame('ok', $result->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://opnsense.local/api/test?a=b'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('k:s')));
    }

    public function test_post_returns_decoded_json(): void
    {
        Http::fake(['*' => Http::response('{"result":"saved"}')]);

        $result = $this->client()->post('/api/test', ['q' => '1'], ['data' => 'value']);

        $this->assertSame('saved', $result->result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://opnsense.local/api/test?q=1'
            && $request->data() === ['data' => 'value']);
    }

    public function test_post_encodes_std_class_payload_as_json_object(): void
    {
        Http::fake(['*' => Http::response('{"result":"saved"}')]);

        $this->client()->post('/api/test', [], new stdClass);

        Http::assertSent(fn (Request $request): bool => $request->body() === '{}');
    }

    public function test_invalid_json_throws_backend_exception(): void
    {
        Http::fake(['*' => Http::response('not json')]);

        $this->expectException(BackendException::class);
        $this->client()->get('/api/test');
    }

    public function test_decode_failure_message_and_log_include_status_content_type_and_excerpt(): void
    {
        $html = "<html>\n  <body>Captive   Portal Login</body>\n</html>".str_repeat('x', 300);
        Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        Log::shouldReceive('debug');
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'GET /api/test')
            && str_contains($m, 'HTTP 200')
            && str_contains($m, 'text/html')
            && ! str_contains($m, base64_encode('k:s'))
            && ! str_contains($m, 'Authorization'));

        try {
            $this->client()->get('/api/test');
            $this->fail('Expected BackendException');
        } catch (BackendException $backendException) {
            $message = $backendException->getMessage();
            $this->assertStringContainsString('HTTP 200', $message);
            $this->assertStringContainsString('text/html', $message);
            $this->assertStringContainsString('<html> <body>Captive Portal Login</body>', $message);
            $this->assertStringNotContainsString("\n", $message);
            $this->assertLessThan(400, strlen($message));
        }
    }

    public function test_http_error_throws_backend_exception(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Error from Opnsense');
        $this->client()->get('/api/test');
    }

    public function test_connection_failure_throws_backend_exception(): void
    {
        Http::fake(['*' => Http::failedConnection('refused')]);

        $this->expectException(BackendException::class);
        $this->client()->post('/api/test');
    }

    public function test_get_shaper_rules_requires_endpoint_and_credentials(): void
    {
        $this->assertStringContainsString('endpoint', OpnSenseClient::fromConfig([])->getShaperRules()['error']);
        $this->assertStringContainsString('credentials', OpnSenseClient::fromConfig(['endpoint' => 'http://x'])->getShaperRules()['error']);
    }

    public function test_get_shaper_rules_maps_rows(): void
    {
        Http::fake(['*' => Http::response(['rows' => [
            ['uuid' => 'u1', 'description' => 'Up', 'sequence' => 3],
            ['uuid' => 'u2'],
        ]])]);

        $result = $this->client()->getShaperRules();

        $this->assertSame([
            ['uuid' => '', 'description' => 'None (no rate limiting)'],
            ['uuid' => 'u1', 'description' => 'Up (seq: 3)'],
            ['uuid' => 'u2', 'description' => 'Unnamed rule (seq: ?)'],
        ], $result['rules']);
        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://opnsense.local/api/trafficshaper/settings/search_rules'
            && $request['rowCount'] === -1);
    }

    public function test_get_shaper_rules_returns_error_on_failure(): void
    {
        Http::fake(['*' => Http::response('nope', 403)]);

        $result = $this->client()->getShaperRules();

        $this->assertSame([], $result['rules']);
        $this->assertStringStartsWith('Failed to fetch shaper rules:', $result['error']);
    }

    public function test_get_zones_requires_endpoint_and_credentials(): void
    {
        $this->assertStringContainsString('endpoint', OpnSenseClient::fromConfig([])->getZones()['error']);
        $this->assertStringContainsString('credentials', OpnSenseClient::fromConfig(['endpoint' => 'http://x'])->getZones()['error']);
    }

    public function test_get_zones_maps_and_sorts_zones(): void
    {
        Http::fake(['*' => Http::response(['zone' => ['zones' => ['zone' => [
            ['zoneid' => '10', 'description' => 'Guest'],
            ['zoneid' => '2'],
        ]]]])]);

        $result = $this->client()->getZones();

        $this->assertSame([
            ['id' => '2', 'name' => 'Zone 2 (ID: 2)'],
            ['id' => '10', 'name' => 'Guest (ID: 10)'],
        ], $result['zones']);
    }

    public function test_get_zones_returns_error_on_exception(): void
    {
        Http::fake(fn () => throw new RuntimeException('Connection refused'));

        $result = $this->client()->getZones();

        $this->assertSame([], $result['zones']);
        $this->assertStringContainsString('Connection refused', $result['error']);
    }
}
