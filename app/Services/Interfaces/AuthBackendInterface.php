<?php

namespace App\Services\Interfaces;

use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

interface AuthBackendInterface
{
    public function __construct(AuthProvider $provider);

    public function redirect(): RedirectResponse;

    public function user(): User;

    public function getRequiredHostnames(): array;
}
