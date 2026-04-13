<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use Carbon\CarbonImmutable;
use phpseclib3\Net\SSH2;

class SshConnection
{
    protected CarbonImmutable $createdAt;

    protected CarbonImmutable $lastUsedAt;

    protected bool $locked = false;

    public function __construct(
        protected string $hostname,
        protected SSH2 $ssh,
    ) {
        $this->createdAt = CarbonImmutable::now();
        $this->lastUsedAt = CarbonImmutable::now();
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getSsh(): SSH2
    {
        return $this->ssh;
    }

    public function getCreatedAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): CarbonImmutable
    {
        return $this->lastUsedAt;
    }

    public function touch(): void
    {
        $this->lastUsedAt = CarbonImmutable::now();
    }

    public function isIdle(int $idleTimeoutSeconds): bool
    {
        return $this->lastUsedAt->diffInSeconds(CarbonImmutable::now()) >= $idleTimeoutSeconds;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    public function unlock(): void
    {
        $this->locked = false;
    }

    public function disconnect(): void
    {
        $this->ssh->disconnect();
    }
}
