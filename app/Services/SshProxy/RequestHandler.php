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
     */
    public function handle(string $method, string $path, array $headers, string $body): ProxyResponse
    {
        // Auth check
        $token = $headers['authorization'] ?? $headers['Authorization'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        if ($token !== $this->apiKey) {
            return new ProxyResponse(status: 401, body: ['error' => 'Unauthorized']);
        }

        if ($method === 'POST' && $path === '/execute') {
            return $this->handleExecute($body);
        }

        if ($method === 'GET' && $path === '/status') {
            return $this->handleStatus();
        }

        return new ProxyResponse(status: 404, body: ['error' => 'Not found']);
    }

    protected function handleExecute(string $body): ProxyResponse
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return new ProxyResponse(status: 400, body: ['error' => 'Invalid JSON body']);
        }

        $hostname = $data['hostname'] ?? null;
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $commands = $data['commands'] ?? null;

        if (! $hostname || ! $username || ! is_array($commands)) {
            return new ProxyResponse(status: 400, body: ['error' => 'Missing required fields: hostname, username, commands']);
        }

        // Check if host is locked
        if ($this->pool->isLocked($hostname)) {
            return new ProxyResponse(status: 409, body: ['error' => 'Host is currently locked by another request']);
        }

        // Get or create connection
        $connection = $this->pool->get($hostname);
        if (! $connection instanceof SshConnection) {
            try {
                $ssh = $this->createSshConnection($hostname, $username, (string) $password);
                $connection = new SshConnection($hostname, $ssh);
                $this->pool->put($hostname, $connection);
            } catch (Throwable $e) {
                return new ProxyResponse(status: 500, body: ['success' => false, 'error' => 'SSH connection failed: '.$e->getMessage()]);
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

        return new ProxyResponse(status: 200, body: [
            'success' => $result->success,
            'output' => array_map(
                fn (CommandOutput $o): array => ['command' => $o->command, 'output' => $o->output],
                $result->output,
            ),
        ] + ($result->error !== null ? ['error' => $result->error] : []));
    }

    protected function handleStatus(): ProxyResponse
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startedAt);

        return new ProxyResponse(status: 200, body: [
            'uptime_seconds' => $uptimeSeconds,
            'connections' => array_map(
                fn (ConnectionStatus $c): array => [
                    'hostname' => $c->hostname,
                    'connected_seconds' => $c->connectedSeconds,
                    'last_used_seconds_ago' => $c->lastUsedSecondsAgo,
                    'locked' => $c->locked,
                ],
                $this->pool->getStatus(),
            ),
        ]);
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
