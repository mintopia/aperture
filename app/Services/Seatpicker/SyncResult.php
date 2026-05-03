<?php

declare(strict_types=1);

namespace App\Services\Seatpicker;

readonly class SyncResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
