<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class VyOsTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/show';

        return ConnectionTester::test(
            'POST',
            $url,
            fn () => Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
                ->asForm()
                ->timeout(10)
                ->post($url, [
                    'data' => (string) json_encode(['op' => 'show', 'path' => ['version']]),
                    'key' => $config['api_key'] ?? '',
                ]),
            'Connected and authenticated successfully',
        );
    }
}
