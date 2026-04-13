<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

class RequestHandler
{
    protected float $startedAt;

    public function __construct(
        protected SshConnectionPool $pool,
        protected CommandExecutor $executor,
        protected string $apiKey,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $path, array $headers, string $body): array
    {
        // Auth check
        $token = $headers['authorization'] ?? $headers['Authorization'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        if ($token !== $this->apiKey) {
            return ['status' => 401, 'body' => ['error' => 'Unauthorized']];
        }

        if ($method === 'POST' && $path === '/execute') {
            return $this->handleExecute($body);
        }

        if ($method === 'GET' && $path === '/status') {
            return $this->handleStatus();
        }

        return ['status' => 404, 'body' => ['error' => 'Not found']];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function handleExecute(string $body): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return ['status' => 400, 'body' => ['error' => 'Invalid JSON body']];
        }

        $hostname = $data['hostname'] ?? null;
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $commands = $data['commands'] ?? null;

        if (! $hostname || ! $username || ! is_array($commands)) {
            return ['status' => 400, 'body' => ['error' => 'Missing required fields: hostname, username, commands']];
        }

        // Check if host is locked
        if ($this->pool->isLocked($hostname)) {
            return ['status' => 409, 'body' => ['error' => 'Host is currently locked by another request']];
        }

        // Get or create connection
        $connection = $this->pool->get($hostname);
        if (! $connection instanceof SshConnection) {
            try {
                $ssh = $this->createSshConnection($hostname, $username, (string) $password);
                $connection = new SshConnection($hostname, $ssh);
                $this->pool->put($hostname, $connection);
            } catch (Throwable $e) {
                return ['status' => 500, 'body' => ['success' => false, 'error' => 'SSH connection failed: '.$e->getMessage()]];
            }
        }

        // Lock, execute, unlock
        $connection->lock();
        $connection->touch();

        try {
            $result = $this->executor->execute($connection->getSsh(), $commands);
        } finally {
            $connection->unlock();
        }

        return ['status' => 200, 'body' => $result];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function handleStatus(): array
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startedAt);

        return [
            'status' => 200,
            'body' => [
                'uptime_seconds' => $uptimeSeconds,
                'connections' => $this->pool->getStatus(),
            ],
        ];
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createSshConnection(string $hostname, string $username, string $password): SSH2
    {
        $ssh = new SSH2($hostname);
        if (! $ssh->login($username, $password)) {
            throw new RuntimeException('SSH authentication failed');
        }

        $ssh->enablePTY();

        return $ssh;
    }
}
