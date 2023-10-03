<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Traits\ToString;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $nickname
 * @property string $email
 * @property int $blocked
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\IpAddress> $ips
 * @property-read int|null $ips_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereBlocked($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 * @mixin \Eloquent
 * @mixin IdeHelperUser
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, ToString;

    protected string $stringDescriptionProperty = 'nickname';

    public function ips(): HasMany
    {
        return $this->hasMany(UserIpAddress::class)->orderBy('last_seen_at', 'desc');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function authentications(): HasMany
    {
        return $this->hasMany(UserAuthentication::class);
    }

    public function hasRole(string|Role $role): bool
    {
        if ($role instanceof Role) {
            $code = $role->code;
        } else {
            $code = $role;
        }
        return $this->roles()->whereCode($code)->count() > 0;
    }

    public function addIp(string $clientIp): IpAddress
    {
        $ip = IpAddress::whereAddress($clientIp)->first();
        if (!$ip) {
            $ip = new IpAddress;
            $ip->address = $clientIp;
            $ip->save();
        }
        $userIp = $this->ips()->whereIpAddressId($ip->id)->first();
        if (!$userIp) {
            $userIp = new UserIpAddress;
            $userIp->user()->associate($this);
            $userIp->ip()->associate($ip);
        }
        $userIp->last_seen_at = Carbon::now();
        $userIp->save();
        return $ip;
    }
}
