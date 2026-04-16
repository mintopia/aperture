<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class NtopNgTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/lua/pro/rest/v2/get/system/data.lua';

        return ConnectionTester::test(
            'GET',
            $url,
            fn () => Http::timeout(10)
                ->withBasicAuth($config['username'] ?? '', $config['password'] ?? '')
                ->get($url),
        );
    }
}
