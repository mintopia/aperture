<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface SupportsInterfaceOutputCapture
{
    public function getPortInterfaceOutput(string $portId): string;
}
