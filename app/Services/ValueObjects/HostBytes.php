<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

class HostBytes
{
    public function __construct(
        public int $received,
        public int $sent,
    ) {}
}
