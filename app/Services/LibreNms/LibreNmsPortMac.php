<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\Interfaces\PortMacInterface;
use Illuminate\Support\Collection;

class LibreNmsPortMac implements PortMacInterface
{
    public function __construct(
        protected LibreNmsService $libreNms,
    ) {}

    public function getForwardingDatabase(): Collection
    {
        return $this->libreNms->getForwardingDatabase();
    }
}
