<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class BorealisTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/oauth2/device';

        return ConnectionTester::test(
            'POST',
            $url,
            fn () => Http::asForm()
                ->timeout(10)
                ->withBasicAuth($config['client_id'] ?? '', $config['client_secret'] ?? '')
                ->post($url, ['scope' => $config['scope'] ?? 'test']),
            'Authenticated and received device code.',
        );
    }
}
