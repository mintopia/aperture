<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class DhcpRange
{
    public function __construct(
        public string $interface,
        public string $type,
        public ?string $subnet,
        public ?string $rangeFrom,
        public ?string $rangeTo,
        public ?string $prefix,
        public ?string $gateway,
        public ?string $description,
    ) {}
}
