<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class TopTalker
{
    public function __construct(
        public string $ip,
        public int $received,
        public int $sent,
        public ?string $nickname = null,
    ) {}
}
