<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use App\Services\Interfaces\SshProxyClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class SshProxyClient implements SshProxyClientInterface
{
    protected Client $client;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 60, int $connectTimeout = 5)
    {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function execute(string $hostname, string $username, string $password, array $commands, int $port = 22, string $channel = 'commands', ?string $privateKey = null, ?string $passphrase = null, ?string $hostKey = null): CommandResult
    {
        try {
            $response = $this->client->post('execute', [
                'json' => [
                    'hostname' => $hostname,
                    'username' => $username,
                    'password' => $password,
                    'commands' => $commands,
                    'port' => $port,
                    'channel' => $channel,
                    'private_key' => $privateKey ?? '',
                    'passphrase' => $passphrase ?? '',
                    'host_key' => $hostKey ?? '',
                ],
            ]);

            /** @var array{success: bool, output: array<int, array{command: string, output: string}>, error?: string, error_code?: string, host_key?: string} $data */
            $data = json_decode((string) $response->getBody(), true);

            return new CommandResult(
                success: $data['success'],
                output: array_map(
                    fn (array $o): CommandOutput => new CommandOutput(command: $o['command'], output: $o['output']),
                    $data['output'],
                ),
                error: $data['error'] ?? null,
                hostKey: filled($data['host_key'] ?? null) ? $data['host_key'] : null,
                errorCode: filled($data['error_code'] ?? null) ? $data['error_code'] : null,
            );
        } catch (ClientException $clientException) {
            if ($clientException->getResponse()->getStatusCode() === 409) {
                throw new RuntimeException('Host is currently locked by another request', $clientException->getCode(), $clientException);
            }

            return $this->resultFromErrorResponse($clientException) ?? throw $clientException;
        } catch (ServerException $serverException) {
            return $this->resultFromErrorResponse($serverException) ?? throw $serverException;
        }
    }

    private function resultFromErrorResponse(BadResponseException $exception): ?CommandResult
    {
        /** @var array{error?: string, error_code?: string}|null $data */
        $data = json_decode((string) $exception->getResponse()->getBody(), true);

        if (! is_array($data) || blank($data['error_code'] ?? null)) {
            return null;
        }

        return new CommandResult(
            success: false,
            output: [],
            error: $data['error'] ?? null,
            errorCode: $data['error_code'],
        );
    }

    public function status(): ProxyStatus
    {
        $response = $this->client->get('status');

        /** @var array{uptime_seconds: int, connections: array<int, array{hostname: string, connected_seconds: int, last_used_seconds_ago: int, locked: bool}>} $data */
        $data = json_decode((string) $response->getBody(), true);

        return new ProxyStatus(
            uptimeSeconds: $data['uptime_seconds'],
            connections: array_map(
                fn (array $c): ConnectionStatus => new ConnectionStatus(
                    hostname: $c['hostname'],
                    connectedSeconds: $c['connected_seconds'],
                    lastUsedSecondsAgo: $c['last_used_seconds_ago'],
                    locked: $c['locked'],
                ),
                $data['connections'],
            ),
        );
    }
}
