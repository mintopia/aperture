<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\Client\Response;
use Throwable;

class ConnectionTester
{
    /**
     * @param  callable(): Response  $httpCall
     */
    public static function test(
        string $method,
        string $url,
        callable $httpCall,
        string $successMessage = 'Connected successfully',
    ): TestConnectionResult {
        try {
            $response = $httpCall();
            $response->throw();

            return new TestConnectionResult(
                success: true,
                message: $successMessage,
                requestMethod: $method,
                requestUrl: $url,
                responseStatus: $response->status(),
                responseBody: $response->body(),
                output: $response->json() ?? $response->body(),
            );
        } catch (Throwable $throwable) {
            return new TestConnectionResult(
                success: false,
                message: 'Connection failed: '.$throwable->getMessage(),
                requestMethod: $method,
                requestUrl: $url,
            );
        }
    }
}
