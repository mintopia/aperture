<?php
namespace App\Services\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Interfaces\AuthBackendInterface;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;

class DiscordAuth implements AuthBackendInterface
{
    public function getRequiredHostnames(): array
    {
        return [
            'discord.com',
            'gateway.discord.gg',
        ];
    }

    public function redirect(): RedirectResponse
    {
        return $this->getDriver()->redirect();
    }

    public function user(): User
    {
        $discordUser = $this->getDriver()->user();

        $userAuth = UserAuthentication::whereAuthProviderId($this->provider->id)->whereExternalId($discordUser->id)->first();
        if (!$userAuth) {
            $linkEmails = Setting::get('auth.linkemails', true);
            $user = null;

            if ($linkEmails) {
                $user = User::whereEmail($discordUser->email)->first();
            }
            if (!$user) {
                $user = new User;
                $user->email = $discordUser->email;
                $user->nickname = $discordUser->user['global_name'] ?? $discordUser->nickname;
                $user->save();

                $role = Role::whereCode('user')->first();
                $user->roles()->attach($role);
            }
            // No user auth, we need to create one

            $userAuth = new UserAuthentication;
            $userAuth->user()->associate($user);
            $userAuth->provider()->associate($this->provider);
            $userAuth->external_id = $discordUser->id;
        }

        // Even if we didn't create the user, still update these
        $userAuth->user->email = $discordUser->email;
        $userAuth->user->nickname = $discordUser->user['global_name'] ?? $discordUser->nickname;
        $userAuth->user->save();

        // Update our user auth
        $userAuth->access_token = $discordUser->token;
        $userAuth->refresh_token = $discordUser->refreshToken;
        $userAuth->token_expires_at = Carbon::now()->addSeconds($discordUser->expiresIn);
        $userAuth->save();

        return $userAuth->user;
    }

    protected function getDriver(): Provider
    {
        $redirectUrl = route('login.handle', ['provider' => 'discord']);
        $config = new Config($this->provider->client_id, $this->provider->client_secret, $redirectUrl, []);
        return Socialite::driver('discord')->setConfig($config);
    }

    public function __construct(protected AuthProvider $provider)
    {
    }
}
