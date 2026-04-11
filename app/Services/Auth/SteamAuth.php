<?php

namespace App\Services\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Interfaces\AuthBackendInterface;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;

class SteamAuth implements AuthBackendInterface
{
    public function supportsBorealis(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredHostnames(): array
    {
        return [
            'steamcommunity.com',
            'community.akamai.steamstatic.com',
            'api.steampowered.com',
            'avatars.akamai.steamstatic.com',
        ];
    }

    public function redirect(): RedirectResponse
    {
        /** @var RedirectResponse */
        return $this->getDriver()->redirect();
    }

    public function user(): User
    {
        /** @var \Laravel\Socialite\Two\User $remoteUser */
        $remoteUser = $this->getDriver()->user();

        $userAuth = UserAuthentication::whereAuthProviderId($this->provider->id)->whereExternalId($remoteUser->id)->first();
        if (! $userAuth) {
            $user = new User;
            $user->nickname = $remoteUser->nickname;
            $user->save();

            $role = Role::whereCode('user')->first();
            $user->roles()->attach($role);

            $userAuth = new UserAuthentication;
            $userAuth->user()->associate($user);
            $userAuth->provider()->associate($this->provider);
            $userAuth->external_id = $remoteUser->id;
            $userAuth->save();
        }

        // Even if we didn't create the user, still update these
        $userAuth->user->nickname = $remoteUser->nickname;
        $userAuth->user->save();

        return $userAuth->user;
    }

    protected function getDriver(): Provider
    {
        $redirectUrl = route('login.handle', ['provider' => 'steam']);
        $config = new Config('', (string) $this->provider->client_secret, $redirectUrl, [
            'allowed_hosts' => [
                parse_url($redirectUrl, PHP_URL_HOST),
            ],
        ]);

        /** @phpstan-ignore method.notFound */
        return Socialite::driver('steam')->setConfig($config);
    }

    public function __construct(protected AuthProvider $provider) {}
}
