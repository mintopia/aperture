<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class OpnSenseTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/api/diagnostics/system/system_time';

        return ConnectionTester::test(
            'GET',
            $url,
            fn () => Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
                ->withBasicAuth($config['key'] ?? '', $config['secret'] ?? '')
                ->timeout(10)
                ->get($url),
            'Connected and authenticated successfully',
        );
    }
}
