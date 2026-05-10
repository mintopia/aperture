<?php

declare(strict_types=1);

namespace App\Enums;

enum PortOperStatus: string
{
    case Up = 'up';
    case Down = 'down';

    public function isUp(): bool
    {
        return $this === self::Up;
    }

    public function isDown(): bool
    {
        return $this === self::Down;
    }
}
