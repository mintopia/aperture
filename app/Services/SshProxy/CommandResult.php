<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class CommandResult
{
    /**
     * @param  array<int, CommandOutput>  $output
     */
    public function __construct(
        public bool $success,
        public array $output,
        public ?string $error = null,
    ) {}
}
