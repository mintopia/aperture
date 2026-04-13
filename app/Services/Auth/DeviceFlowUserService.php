<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceFlowUserService
{
    public function findOrCreateFromDeviceFlow(UserInfo $userInfo, AuthResult $result): User
    {
        return DB::transaction(function () use ($userInfo, $result): User {
            $user = User::whereExternalId($userInfo->id)->first();

            if (! $user && $userInfo->email) {
                $user = User::whereEmail($userInfo->email)->first();
            }

            if (! $user) {
                $user = new User;
                $user->email = (string) $userInfo->email;
            }

            $user->nickname = $userInfo->nickname;
            $user->avatar_url = $userInfo->avatarUrl;
            $user->external_id = $userInfo->id;
            $user->access_token = $result->accessToken;
            $user->refresh_token = (string) $result->refreshToken;
            $user->token_expires_at = now()->addSeconds($result->expiresIn);
            $user->save();

            return $user;
        });
    }
}
