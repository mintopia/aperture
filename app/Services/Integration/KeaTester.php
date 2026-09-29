<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\Kea\KeaClient;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class KeaTester implements TestableIntegration
{
    /**
     * @var list<string>
     */
    private const REQUIRED_COMMANDS_V4 = ['lease4-get-page', 'config-get'];

    /**
     * @var list<string>
     */
    private const REQUIRED_COMMANDS_V6 = ['lease6-get-page', 'config-get'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $v4Endpoint = $this->nonEmptyString($config['endpoint_v4'] ?? null);
        $v6Endpoint = $this->nonEmptyString($config['endpoint_v6'] ?? null);

        if ($v4Endpoint === null && $v6Endpoint === null) {
            return new TestConnectionResult(
                success: false,
                message: 'No Endpoint configured. Set the IPv4 Endpoint and/or the IPv6 Endpoint in the integration settings.',
            );
        }

        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $results = [];

        if ($v4Endpoint !== null) {
            $results[] = $this->testEndpoint(
                label: 'IPv4',
                endpoint: $v4Endpoint,
                username: $this->nonEmptyString($config['username_v4'] ?? null),
                password: $this->nonEmptyString($config['password_v4'] ?? null),
                verifySsl: $verifySsl,
                service: 'dhcp4',
                requiredCommands: self::REQUIRED_COMMANDS_V4,
            );
        }

        if ($v6Endpoint !== null) {
            $results[] = $this->testEndpoint(
                label: 'IPv6',
                endpoint: $v6Endpoint,
                username: $this->nonEmptyString($config['username_v6'] ?? null),
                password: $this->nonEmptyString($config['password_v6'] ?? null),
                verifySsl: $verifySsl,
                service: 'dhcp6',
                requiredCommands: self::REQUIRED_COMMANDS_V6,
            );
        }

        if (count($results) === 1) {
            return $results[0];
        }

        return new TestConnectionResult(
            success: array_reduce(
                $results,
                fn (bool $carry, TestConnectionResult $result): bool => $carry && $result->success,
                true
            ),
            message: implode(' ', array_map(fn (TestConnectionResult $result): string => $result->message, $results)),
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $requiredCommands
     */
    private function testEndpoint(
        string $label,
        string $endpoint,
        ?string $username,
        ?string $password,
        bool $verifySsl,
        string $service,
        array $requiredCommands,
    ): TestConnectionResult {
        $client = new KeaClient(
            endpoint: $endpoint,
            username: $username,
            password: $password,
            verifySsl: $verifySsl,
            service: $service,
        );

        try {
            $commands = $client->listCommands();
        } catch (ConnectionException $exception) {
            return new TestConnectionResult(
                success: false,
                message: sprintf('Could not reach the %s Endpoint: ', $label).$exception->getMessage(),
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            if ($status === 401 || $status === 403) {
                return new TestConnectionResult(
                    success: false,
                    message: sprintf('Authentication failed on the %s Endpoint. Check the configured username and password.', $label),
                    requestMethod: 'POST',
                    requestUrl: $endpoint,
                    responseStatus: $status,
                );
            }

            return new TestConnectionResult(
                success: false,
                message: sprintf('Could not reach the %s Endpoint: HTTP %s.', $label, $status),
                requestMethod: 'POST',
                requestUrl: $endpoint,
                responseStatus: $status,
            );
        } catch (Throwable $throwable) {
            return new TestConnectionResult(
                success: false,
                message: sprintf('Kea reported an error on the %s Endpoint: ', $label).$throwable->getMessage(),
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        }

        $missing = array_values(array_diff($requiredCommands, $commands));

        if ($missing !== []) {
            return new TestConnectionResult(
                success: false,
                message: sprintf('Connected to the %s Endpoint, but the lease_cmds hook appears to be missing: ', $label).implode(', ', $missing).' not available.',
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        }

        return new TestConnectionResult(
            success: true,
            message: sprintf('Connected to the %s Endpoint successfully.', $label),
            requestMethod: 'POST',
            requestUrl: $endpoint,
        );
    }
}
