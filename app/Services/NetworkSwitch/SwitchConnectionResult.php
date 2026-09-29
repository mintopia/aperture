<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use Throwable;

readonly class SwitchConnectionResult
{
    public function __construct(
        public bool $success,
        public int $portCount = 0,
        public float $duration = 0.0,
        public ?Throwable $exception = null,
    ) {}
}
