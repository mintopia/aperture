<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class ProxyResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
