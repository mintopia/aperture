<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Thin wrapper that executes a single HTTP call and maps the outcome to a
 * TestConnectionResult, eliminating the try/catch boilerplate duplicated
 * across every per-integration tester.
 */
class ConnectionTester
{
    /**
     * Execute $httpCall and return a normalised TestConnectionResult.
     *
     * @param  callable(): Response  $httpCall  Closure that performs the HTTP request and returns an Illuminate Response.
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
