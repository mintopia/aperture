<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class CommandOutput
{
    public function __construct(
        public string $command,
        public string $output,
    ) {}
}
