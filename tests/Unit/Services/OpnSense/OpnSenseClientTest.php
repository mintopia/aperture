<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpnSense;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\OpnSense\OpnSenseClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fake;
use Tests\TestCase;

class OpnSenseClientTest extends TestCase
{
    private function client(): OpnSenseClient
    {
        return new OpnSenseClient('https://opnsense.test', 'key', 'secret');
    }

    public function test_get_returns_decoded_json(): void
    {
        Http::fake(['opnsense.test/*' => Http::response('{"status":"ok"}')]);

        $result = $this->client()->get('/api/test', ['a' => 'b']);

        $this->assertSame('ok', $result->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://opnsense.test/api/test')
            && str_contains($request->url(), 'a=b')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret')));
    }

    public function test_post_returns_decoded_json(): void
    {
        Http::fake(['opnsense.test/*' => Http::response('{"result":"saved"}')]);

        $result = $this->client()->post('/api/test', [], ['data' => 'value']);

        $this->assertSame('saved', $result->result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['data' => 'value']);
    }

    public function test_post_encodes_std_class_payload_as_json_object(): void
    {
        Http::fake(['opnsense.test/*' => Http::response('{"result":"saved"}')]);

        $this->client()->post('/api/test', [], new \stdClass);

        Http::assertSent(fn (Request $request): bool => $request->body() === '{}');
    }

    public function test_invalid_json_throws_backend_exception(): void
    {
        Http::fake(['opnsense.test/*' => Http::response('not json')]);

        $this->expectException(BackendException::class);
        $this->client()->get('/api/test');
    }

    public function test_http_error_is_wrapped_in_backend_exception(): void
    {
        Http::fake(['opnsense.test/*' => Http::response('nope', 500)]);

        $this->expectException(BackendException::class);
        $this->client()->get('/api/test');
    }

    public function test_connection_failure_is_wrapped_in_backend_exception(): void
    {
        Fake::sequence([new ConnectionException('refused')]);

        $this->expectException(BackendException::class);
        $this->client()->get('/api/test');
    }
}
