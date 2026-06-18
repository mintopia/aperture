<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface SwitchCommandTransportInterface
{
    public function execute(string $command): string;

    /**
     * @param  array<int, string>  $commands
     * @return array<string, string>
     */
    public function executeMultiple(array $commands): array;

    public function isConnected(): bool;

    public function disconnect(): void;
}
