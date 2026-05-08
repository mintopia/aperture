<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ToString;
use App\Services\NetworkRangeService;
use Database\Factories\IpAddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\IpAddress
 *
 * @property int $id
 * @property string $address
 * @property bool $internet_enabled
 * @property bool $rate_limit_enabled
 * @property bool $dns_filtering_enabled
 * @property string|null $comment
 * @property Carbon $last_seen_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MacAddress> $macAddresses
 * @property-read int|null $mac_addresses_count
 * @property-read Collection<int, DhcpLease> $dhcpLeases
 * @property-read int|null $dhcp_leases_count
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read int|null $audit_logs_count
 * @property-read Collection<int, UserIpAddress> $users
 * @property-read int|null $users_count
 * @property-read IpAddressMacAddress $pivot
 *
 * @method static IpAddressFactory factory($count = null, $state = [])
 * @method static Builder|IpAddress newModelQuery()
 * @method static Builder|IpAddress newQuery()
 * @method static Builder|IpAddress query()
 * @method static Builder|IpAddress whereAddress($value)
 * @method static Builder<static>|IpAddress whereComment($value)
 * @method static Builder<static>|IpAddress whereCreatedAt($value)
 * @method static Builder<static>|IpAddress whereDnsFilteringEnabled($value)
 * @method static Builder<static>|IpAddress whereExpiresAt($value)
 * @method static Builder<static>|IpAddress whereId($value)
 * @method static Builder<static>|IpAddress whereInternetEnabled($value)
 * @method static Builder<static>|IpAddress whereLastSeenAt($value)
 * @method static Builder<static>|IpAddress whereRateLimitEnabled($value)
 * @method static Builder|IpAddress whereUpdatedAt($value)
 *
 * @mixin IdeHelperIpAddress
 * @mixin \Eloquent
 */
class IpAddress extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ToString;

    protected string $stringDescriptionProperty = 'address';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'address',
        'internet_enabled',
        'rate_limit_enabled',
        'dns_filtering_enabled',
        'comment',
        'last_seen_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internet_enabled' => 'boolean',
            'rate_limit_enabled' => 'boolean',
            'dns_filtering_enabled' => 'boolean',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'address';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $field ??= $this->getRouteKeyName();

        $existing = static::where($field, $value)->first();
        if ($existing !== null) {
            return $existing;
        }

        if (! app(NetworkRangeService::class)->isManaged((string) $value)) {
            return null;
        }

        return static::create([
            'address' => $value,
            'last_seen_at' => now(),
        ]);
    }

    /** @return HasMany<UserIpAddress, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(UserIpAddress::class, 'ip_address_id')->orderBy('last_seen_at', 'desc');
    }

    /** @return BelongsToMany<MacAddress, $this, IpAddressMacAddress> */
    public function macAddresses(): BelongsToMany
    {
        return $this->belongsToMany(MacAddress::class, 'ip_address_mac_address')
            ->using(IpAddressMacAddress::class)
            ->withPivot('source', 'last_seen_at')
            ->withTimestamps();
    }

    /** @return HasMany<DhcpLease, $this> */
    public function dhcpLeases(): HasMany
    {
        return $this->hasMany(DhcpLease::class);
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function currentMac(): ?MacAddress
    {
        return $this->macAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->first();
    }
}
