<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface SupportsBulkOperations
{
    /**
     * Fetch all interface running configs in a single bulk command.
     *
     * @return array<string, string> interface name => config text
     */
    public function getAllPortRunningConfigs(): array;

    /**
     * Fetch all interface output (show interface) in a single bulk command.
     *
     * @return array<string, string> interface name => raw interface output
     */
    public function getAllPortInterfaceOutputs(): array;
}
