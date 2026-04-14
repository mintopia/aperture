<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Models\User;
use App\Services\ValueObjects\ActiveSession;
use Illuminate\Support\Collection;

interface CaptivePortalInterface
{
    public function grantAccess(string $ipAddress, User $user): bool;

    public function revokeAccess(string $ipAddress, User $user): bool;

    public function isAllowed(string $ipAddress): bool;

    /** @return Collection<int, ActiveSession> */
    public function listActiveSessions(): Collection;
}
