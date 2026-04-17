<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class PrometheusTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/api/v1/status/buildinfo';

        $http = Http::withOptions([
            'verify' => (bool) ($config['verify_ssl'] ?? true),
        ])->timeout(10);

        if (! empty($config['bearer_token'])) {
            $http = $http->withToken($config['bearer_token']);
        }

        return ConnectionTester::test(
            'GET',
            $url,
            fn () => $http->get($url),
        );
    }
}
