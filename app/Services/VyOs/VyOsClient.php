<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class VyOsClient
{
    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private bool $verifySsl = true,
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    /**
     * Retrieve configuration data from VyOS.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function retrieve(array $path): array
    {
        return $this->request('/retrieve', 'showConfig', $path);
    }

    /**
     * Run an operational show command on VyOS.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function show(array $path): array
    {
        return $this->request('/show', 'show', $path);
    }

    /**
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function request(string $uri, string $op, array $path): array
    {
        $response = Http::withOptions(['verify' => $this->verifySsl])
            ->asForm()
            ->timeout(15)
            ->post($this->endpoint.$uri, [
                'data' => (string) json_encode(['op' => $op, 'path' => $path]),
                'key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('VyOS API request failed: HTTP '.$response->status());
        }

        /** @var array{success: bool, data: mixed, error: string|null} $body */
        $body = $response->json();

        if (! $body['success']) {
            throw new RuntimeException('VyOS API error: '.($body['error'] ?? 'Unknown error'));
        }

        return (array) $body['data'];
    }
}
