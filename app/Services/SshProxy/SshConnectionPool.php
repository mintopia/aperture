<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

class SshConnectionPool
{
    /** @var array<string, SshConnection> keyed by hostname */
    protected array $connections = [];

    public function __construct(
        protected int $idleTimeoutSeconds = 600,
    ) {}

    public function get(string $hostname): ?SshConnection
    {
        return $this->connections[$hostname] ?? null;
    }

    public function put(string $hostname, SshConnection $connection): void
    {
        $this->connections[$hostname] = $connection;
    }

    public function remove(string $hostname): void
    {
        if (isset($this->connections[$hostname])) {
            $this->connections[$hostname]->disconnect();
            unset($this->connections[$hostname]);
        }
    }

    /**
     * @return int Number of connections removed
     */
    public function sweepIdle(): int
    {
        $removed = 0;
        foreach ($this->connections as $hostname => $connection) {
            if ($connection->isLocked()) {
                continue;
            }

            if ($connection->isIdle($this->idleTimeoutSeconds)) {
                $connection->disconnect();
                unset($this->connections[$hostname]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array<int, ConnectionStatus>
     */
    public function getStatus(): array
    {
        $now = now();
        $status = [];
        foreach ($this->connections as $hostname => $connection) {
            $status[] = new ConnectionStatus(
                hostname: $hostname,
                connectedSeconds: (int) $connection->getCreatedAt()->diffInSeconds($now),
                lastUsedSecondsAgo: (int) $connection->getLastUsedAt()->diffInSeconds($now),
                locked: $connection->isLocked(),
            );
        }

        return $status;
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }

    public function isLocked(string $hostname): bool
    {
        $connection = $this->connections[$hostname] ?? null;

        return $connection !== null && $connection->isLocked();
    }
}
