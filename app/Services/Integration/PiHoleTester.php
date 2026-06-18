<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class PiHoleTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/api/auth';

        return ConnectionTester::test(
            'POST',
            $url,
            fn () => Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
                ->asJson()
                ->timeout(10)
                ->post($url, ['password' => $config['password'] ?? '']),
            'Connected and authenticated successfully',
        );
    }
}
