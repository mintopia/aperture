<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class SeatpickerTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/api/v1/events';
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);

        return ConnectionTester::test(
            'GET',
            $url,
            fn () => Http::withOptions(['verify' => $verifySsl])
                ->withToken($config['api_key'] ?? '')
                ->acceptJson()
                ->timeout(10)
                ->get($url),
        );
    }
}
