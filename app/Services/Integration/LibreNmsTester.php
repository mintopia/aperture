<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class LibreNmsTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/api/v0';

        return ConnectionTester::test(
            'GET',
            $url,
            fn () => Http::withHeaders(['X-Auth-Token' => $config['api_key'] ?? ''])
                ->timeout(10)
                ->get($url),
        );
    }
}
