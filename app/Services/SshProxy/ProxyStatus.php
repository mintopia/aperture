<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class ProxyStatus
{
    /**
     * @param  array<int, ConnectionStatus>  $connections
     */
    public function __construct(
        public int $uptimeSeconds,
        public array $connections,
    ) {}
}
