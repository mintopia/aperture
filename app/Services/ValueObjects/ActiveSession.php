<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class ActiveSession
{
    public function __construct(
        public string $ip,
        public string $user,
    ) {}
}
