<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Traits\ToString;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $nickname
 * @property string $email
 * @property int $blocked
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Collection<int, IpAddress> $ips
 * @property-read int|null $ips_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder|User newModelQuery()
 * @method static Builder|User newQuery()
 * @method static Builder|User query()
 * @method static Builder|User whereBlocked($value)
 * @method static Builder|User whereCreatedAt($value)
 * @method static Builder|User whereEmail($value)
 * @method static Builder|User whereId($value)
 * @method static Builder|User whereNickname($value)
 * @method static Builder|User whereUpdatedAt($value)
 *
 * @property-read Collection<int, UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 *
 * @mixin \Eloquent
 * @mixin IdeHelperUser
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use ToString;

    protected string $stringDescriptionProperty = 'nickname';

    /** @return HasMany<UserIpAddress, $this> */
    public function ips(): HasMany
    {
        return $this->hasMany(UserIpAddress::class)->orderBy('last_seen_at', 'desc');
    }

    /** @return HasMany<MacAddress, $this> */
    public function macAddresses(): HasMany
    {
        return $this->hasMany(MacAddress::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** @return HasMany<UserAuthentication, $this> */
    public function authentications(): HasMany
    {
        return $this->hasMany(UserAuthentication::class);
    }

    public function hasRole(string|Role $role): bool
    {
        $code = $role instanceof Role ? $role->code : $role;

        return $this->roles()->whereCode($code)->count() > 0;
    }

    public function addIp(string $clientIp): IpAddress
    {
        $ip = IpAddress::whereAddress($clientIp)->first();
        if (! $ip) {
            $ip = new IpAddress;
            $ip->address = $clientIp;
            $ip->last_seen_at = Carbon::now();
            $ip->save();
        }

        $userIp = $this->ips()->whereIpAddressId($ip->id)->first();
        if (! $userIp) {
            $userIp = new UserIpAddress;
            $userIp->user()->associate($this);
            $userIp->ip()->associate($ip);
        }

        $userIp->last_seen_at = Carbon::now();
        $userIp->save();

        return $ip;
    }
}
