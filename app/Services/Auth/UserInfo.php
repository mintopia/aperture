<?php

declare(strict_types=1);

namespace App\Services\Auth;

class UserInfo
{
    public function __construct(
        public string $id,
        public string $nickname,
        public ?string $email = null,
        public ?string $avatarUrl = null,
    ) {}
}
