<?php

declare(strict_types=1);

namespace App\Services\Auth;

class AuthResult
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        public ?string $refreshToken = null,
        public ?string $scope = null,
    ) {}
}
