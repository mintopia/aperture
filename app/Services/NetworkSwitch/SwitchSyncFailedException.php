<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use RuntimeException;
use Throwable;

class SwitchSyncFailedException extends RuntimeException
{
    public static function recorded(Throwable $previous): self
    {
        return new self($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
