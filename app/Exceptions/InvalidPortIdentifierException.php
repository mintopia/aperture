<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidPortIdentifierException extends InvalidArgumentException
{
    public static function forPortId(string $portId): self
    {
        return new self('Invalid port identifier: '.$portId);
    }
}
