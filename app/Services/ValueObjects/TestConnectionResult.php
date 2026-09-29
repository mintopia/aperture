<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class TestConnectionResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?string $requestMethod = null,
        public ?string $requestUrl = null,
        public ?int $responseStatus = null,
        public ?string $responseBody = null,
        public mixed $output = null,
    ) {}
}
