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
     * Commands the `lease_cmds` hook must expose for Aperture to work with Kea.
     *
     * @var list<string>
     */
    private const REQUIRED_COMMANDS = ['lease4-get-page', 'config-get'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = $config['endpoint_v4'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            return new TestConnectionResult(
                success: false,
                message: 'No IPv4 Endpoint configured. Set the IPv4 Endpoint in the integration settings.',
            );
        }

        $username = $config['username_v4'] ?? null;
        $password = $config['password_v4'] ?? null;

        $client = new KeaClient(
            endpoint: $endpoint,
            username: is_string($username) && $username !== '' ? $username : null,
            password: is_string($password) && $password !== '' ? $password : null,
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
        );

        try {
            $commands = $client->listCommands();
        } catch (ConnectionException $exception) {
            return new TestConnectionResult(
                success: false,
                message: 'Could not reach the IPv4 Endpoint: '.$exception->getMessage(),
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            if ($status === 401 || $status === 403) {
                return new TestConnectionResult(
                    success: false,
                    message: 'Authentication failed on the IPv4 Endpoint. Check the configured username and password.',
                    requestMethod: 'POST',
                    requestUrl: $endpoint,
                    responseStatus: $status,
                );
            }

            return new TestConnectionResult(
                success: false,
                message: 'Could not reach the IPv4 Endpoint: HTTP '.$status.'.',
                requestMethod: 'POST',
                requestUrl: $endpoint,
                responseStatus: $status,
            );
        } catch (Throwable $throwable) {
            return new TestConnectionResult(
                success: false,
                message: 'Kea reported an error on the IPv4 Endpoint: '.$throwable->getMessage(),
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        }

        $missing = array_values(array_diff(self::REQUIRED_COMMANDS, $commands));

        if ($missing !== []) {
            return new TestConnectionResult(
                success: false,
                message: 'Connected to the IPv4 Endpoint, but the lease_cmds hook appears to be missing: '.implode(', ', $missing).' not available.',
                requestMethod: 'POST',
                requestUrl: $endpoint,
            );
        }

        return new TestConnectionResult(
            success: true,
            message: 'Connected to the IPv4 Endpoint successfully.',
            requestMethod: 'POST',
            requestUrl: $endpoint,
        );
    }
}
