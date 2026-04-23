<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Traits\ToString;
use App\Services\IpPolicyService;
use App\Services\NetworkRangeService;
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
use Illuminate\Support\Carbon;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable as WebAuthnAuthenticatableContract;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $nickname
 * @property string $email
 * @property bool $internet_blocked
 * @property bool $internet_enabled
 * @property bool $rate_limit_enabled
 * @property bool $dns_filtering_enabled
 * @property string|null $external_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $avatar_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
 * @method static Builder|User whereInternetBlocked($value)
 * @method static Builder|User whereCreatedAt($value)
 * @method static Builder|User whereEmail($value)
 * @method static Builder|User whereId($value)
 * @method static Builder|User whereNickname($value)
 * @method static Builder|User whereExternalId($value)
 * @method static Builder|User whereUpdatedAt($value)
 *
 * @mixin IdeHelperUser
 *
 * @property string|null $password
 * @property-read Collection<int, MacAddress> $macAddresses
 * @property-read int|null $mac_addresses_count
 * @property-read Collection<int, UserParameter> $parameters
 * @property-read int|null $parameters_count
 * @property-read Collection<int, WebAuthnCredential> $webAuthnCredentials
 * @property-read int|null $web_authn_credentials_count
 *
 * @method static Builder<static>|User whereAccessToken($value)
 * @method static Builder<static>|User whereAvatarUrl($value)
 * @method static Builder<static>|User whereDnsFilteringEnabled($value)
 * @method static Builder<static>|User whereInternetEnabled($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User whereRateLimitEnabled($value)
 * @method static Builder<static>|User whereRefreshToken($value)
 * @method static Builder<static>|User whereTokenExpiresAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements WebAuthnAuthenticatableContract
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use ToString;
    use WebAuthnAuthentication;

    protected string $stringDescriptionProperty = 'nickname';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'internet_blocked' => 'boolean',
            'internet_enabled' => 'boolean',
            'rate_limit_enabled' => 'boolean',
            'dns_filtering_enabled' => 'boolean',
        ];
    }

    /**
     * Returns displayable data to be used to create WebAuthn Credentials.
     */
    public function webAuthnData(): WebAuthnData
    {
        return WebAuthnData::make($this->email, $this->nickname);
    }

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

    /** @return HasMany<UserParameter, $this> */
    public function parameters(): HasMany
    {
        return $this->hasMany(UserParameter::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function hasRole(string|Role $role): bool
    {
        $code = $role instanceof Role ? $role->code : $role;

        return $this->roles()->whereCode($code)->count() > 0;
    }

    public function addIp(string $clientIp, bool $cascade = true): ?IpAddress
    {
        if (! app(NetworkRangeService::class)->isManaged($clientIp)) {
            return null;
        }

        $ip = IpAddress::whereAddress($clientIp)->first();
        if (! $ip) {
            $ip = new IpAddress;
            $ip->address = $clientIp;
            $ip->last_seen_at = now();
            $ip->save();
        }

        $userIp = $this->ips()->whereIpAddressId($ip->id)->first();
        if (! $userIp) {
            $userIp = new UserIpAddress;
            $userIp->user()->associate($this);
            $userIp->ip()->associate($ip);
        }

        $userIp->last_seen_at = now();
        $userIp->save();

        app(IpPolicyService::class)->applyUserPolicy($this, $ip);

        if ($cascade) {
            $this->cascadeMacOwnership($ip);
        }

        return $ip;
    }

    /**
     * Assign MAC ownership and cascade IP associations via shared MACs.
     * Depth-limited to one hop (IP -> MAC -> sibling IPs).
     */
    private function cascadeMacOwnership(IpAddress $ip): void
    {
        $macs = $ip->macAddresses()->get();

        foreach ($macs as $mac) {
            // Assign MAC ownership if unowned
            if ($mac->user_id === null) {
                $mac->user_id = $this->id;
                $mac->save();

                AuditLog::record(
                    action: 'mac.user_assigned',
                    subject: $mac,
                    related: $ip,
                    actor: $this,
                    process: 'portal_login',
                    metadata: ['user_id' => $this->id],
                );
            }

            // Only cascade sibling IPs for MACs we own
            if ((int) $mac->user_id !== (int) $this->id) {
                continue;
            }

            // Find sibling IPs on this MAC (one hop)
            $siblingIps = $mac->ipAddresses()->where('ip_addresses.id', '!=', $ip->id)->get();

            foreach ($siblingIps as $siblingIp) {
                // Skip if another user already owns this IP
                $existingOwner = UserIpAddress::where('ip_address_id', $siblingIp->id)->first();
                if ($existingOwner !== null && (int) $existingOwner->user_id !== (int) $this->id) {
                    continue;
                }

                // Use addIp with cascade=false to prevent recursion
                $cascaded = $this->addIp($siblingIp->address, cascade: false);

                if ($cascaded instanceof IpAddress) {
                    AuditLog::record(
                        action: 'ip.user_cascaded',
                        subject: $siblingIp,
                        related: $mac,
                        actor: $this,
                        process: 'portal_login',
                        metadata: ['source_ip' => $ip->address],
                    );
                }
            }
        }
    }
}
