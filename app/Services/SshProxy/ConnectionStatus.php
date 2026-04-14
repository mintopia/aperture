<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class ConnectionStatus
{
    public function __construct(
        public string $hostname,
        public int $connectedSeconds,
        public int $lastUsedSecondsAgo,
        public bool $locked,
    ) {}
}
