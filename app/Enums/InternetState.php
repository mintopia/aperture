<?php

declare(strict_types=1);

namespace App\Enums;

enum InternetState: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';
    case Undecided = 'undecided';

    public static function fromColumn(?bool $value): self
    {
        return match ($value) {
            true => self::Allowed,
            false => self::Blocked,
            null => self::Undecided,
        };
    }

    public function toColumn(): ?bool
    {
        return match ($this) {
            self::Allowed => true,
            self::Blocked => false,
            self::Undecided => null,
        };
    }

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }
}
