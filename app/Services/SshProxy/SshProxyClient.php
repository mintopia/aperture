<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use App\Services\Interfaces\SshProxyClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SshProxyClient implements SshProxyClientInterface
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected int $timeout = 60,
        protected int $connectTimeout = 5,
    ) {}

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->apiKey)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson()
            ->asJson();
    }

    public function execute(string $hostname, string $username, string $password, array $commands, int $port = 22, string $channel = 'commands', ?string $privateKey = null, ?string $passphrase = null, ?string $hostKey = null): CommandResult
    {
        try {
            $response = $this->request()->post('execute', [
                'hostname' => $hostname,
                'username' => $username,
                'password' => $password,
                'commands' => $commands,
                'port' => $port,
                'channel' => $channel,
                'private_key' => $privateKey ?? '',
                'passphrase' => $passphrase ?? '',
                'host_key' => $hostKey ?? '',
            ])->throw();

            /** @var array{success: bool, output: array<int, array{command: string, output: string}>, error?: string, error_code?: string, host_key?: string} $data */
            $data = $response->json();

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
        } catch (RequestException $requestException) {
            if ($requestException->response->status() === 409) {
                throw new RuntimeException('Host is currently locked by another request', $requestException->getCode(), $requestException);
            }

            return $this->resultFromErrorResponse($requestException) ?? throw $requestException;
        }
    }

    private function resultFromErrorResponse(RequestException $exception): ?CommandResult
    {
        /** @var array{error?: string, error_code?: string}|null $data */
        $data = $exception->response->json();

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
}
