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
     * @param  list<string>|null  $requiredJsonKeys  null skips body validation; [] requires any JSON object/array
     */
    public static function test(
        string $method,
        string $url,
        callable $httpCall,
        string $successMessage = 'Connected successfully',
        ?array $requiredJsonKeys = null,
        string $service = 'The service',
    ): TestConnectionResult {
        try {
            $response = $httpCall();
            $response->throw();

            if ($requiredJsonKeys !== null) {
                $problem = self::validateBody($response, $requiredJsonKeys, $service);

                if ($problem !== null) {
                    return new TestConnectionResult(
                        success: false,
                        message: $problem,
                        requestMethod: $method,
                        requestUrl: $url,
                        responseStatus: $response->status(),
                        responseBody: $response->body(),
                    );
                }
            }

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

    /**
     * @param  list<string>  $requiredKeys
     */
    private static function validateBody(Response $response, array $requiredKeys, string $service): ?string
    {
        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            $type = trim(explode(';', $response->header('Content-Type'))[0]);

            return sprintf(
                '%s returned a non-JSON response (%s) — is a captive portal or proxy intercepting requests?',
                $service,
                $type !== '' ? $type : 'unknown content type',
            );
        }

        $missing = array_values(array_filter($requiredKeys, fn (string $key): bool => ! array_key_exists($key, $decoded)));

        if ($missing !== []) {
            return sprintf('%s returned an unexpected response: missing %s.', $service, implode(', ', $missing));
        }

        return null;
    }
}
