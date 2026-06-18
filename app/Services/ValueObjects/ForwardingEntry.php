<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class ForwardingEntry
{
    public function __construct(
        public string $mac,
        public string $port,
        public int $vlan,
    ) {}
}
