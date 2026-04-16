<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ValueObjects\TestConnectionResult;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

class NtopNgService
{
    protected Client $client;

    public function __construct(string $endpoint, string $username, string $password, protected int $interface)
    {
        $this->client = new Client([
            'base_uri' => $endpoint,
            'auth' => [
                $username,
                $password,
            ],
        ]);
    }

    public function getStats(string $ip): stdClass
    {
        $response = $this->client->get('/lua/rest/v2/get/host/data.lua', [
            'query' => [
                'ifid' => $this->interface,
                'host' => $ip,
            ],
        ]);

        /** @var stdClass */
        return json_decode((string) $response->getBody());
    }

    /**
     * Test connectivity to the ntopng API.
     *
     * @param  array<string, mixed>  $config  Merged DB + request config
     */
    public static function testConnection(array $config): TestConnectionResult
    {
        $requestMethod = 'GET';
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $requestUrl = $endpoint.'/lua/pro/rest/v2/get/system/data.lua';

        try {
            $response = Http::timeout(10)->get($requestUrl);

            $response->throw();

            return new TestConnectionResult(
                success: true,
                message: 'Connected successfully',
                requestMethod: $requestMethod,
                requestUrl: $requestUrl,
                responseStatus: $response->status(),
                responseBody: $response->body(),
                output: $response->json() ?? $response->body(),
            );
        } catch (Throwable $e) {
            return new TestConnectionResult(
                success: false,
                message: 'Connection failed: '.$e->getMessage(),
                requestMethod: $requestMethod,
                requestUrl: $requestUrl,
            );
        }
    }
}
