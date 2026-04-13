<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

class SshProxyClient implements SshProxyClientInterface
{
    protected Client $client;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function execute(string $hostname, string $username, string $password, array $commands): array
    {
        try {
            $response = $this->client->post('execute', [
                'json' => [
                    'hostname' => $hostname,
                    'username' => $username,
                    'password' => $password,
                    'commands' => $commands,
                ],
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (ClientException $clientException) {
            if ($clientException->getResponse()->getStatusCode() === 409) {
                throw new RuntimeException('Host is currently locked by another request', $clientException->getCode(), $clientException);
            }

            throw $clientException;
        }
    }

    public function status(): array
    {
        $response = $this->client->get('status');

        return json_decode((string) $response->getBody(), true);
    }
}
