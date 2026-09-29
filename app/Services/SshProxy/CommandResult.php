<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

readonly class CommandResult
{
    public const HOST_KEY_MISMATCH = 'host_key_mismatch';

    public const INVALID_PRIVATE_KEY = 'invalid_private_key';

    /**
     * @param  array<int, CommandOutput>  $output
     */
    public function __construct(
        public bool $success,
        public array $output,
        public ?string $error = null,
        public ?string $hostKey = null,
        public ?string $errorCode = null,
    ) {}

    public function failureMessage(string $fallback = 'Switch proxy command execution failed.'): string
    {
        $detail = $this->error ?? $fallback;

        return match ($this->errorCode) {
            self::HOST_KEY_MISMATCH => 'SSH host key mismatch: the switch presented a different host key than the pinned one. If the change is expected, reset the pinned host key on the switch edit page. ('.$detail.')',
            self::INVALID_PRIVATE_KEY => 'The configured SSH private key or passphrase is invalid. ('.$detail.')',
            default => $detail,
        };
    }
}
