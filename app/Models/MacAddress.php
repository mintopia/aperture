<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\NormalizeMacAddress;
use Database\Factories\MacAddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $mac_address
 * @property int|null $user_id
 * @property string $source
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property IpAddressMacAddress $pivot
 * @property-read Collection<int, IpAddress> $ipAddresses
 * @property-read int|null $ip_addresses_count
 * @property-read Collection<int, DhcpLease> $dhcpLeases
 * @property-read int|null $dhcp_leases_count
 * @property-read Collection<int, SwitchPort> $switchPorts
 * @property-read int|null $switch_ports_count
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read int|null $audit_logs_count
 * @property-read User|null $user
 *
 * @method static MacAddressFactory factory($count = null, $state = [])
 * @method static Builder<static>|MacAddress newModelQuery()
 * @method static Builder<static>|MacAddress newQuery()
 * @method static Builder<static>|MacAddress query()
 * @method static Builder<static>|MacAddress whereCreatedAt($value)
 * @method static Builder<static>|MacAddress whereDescription($value)
 * @method static Builder<static>|MacAddress whereId($value)
 * @method static Builder<static>|MacAddress whereMacAddress($value)
 * @method static Builder<static>|MacAddress whereSource($value)
 * @method static Builder<static>|MacAddress whereUpdatedAt($value)
 * @method static Builder<static>|MacAddress whereUserId($value)
 *
 * @mixin \Eloquent
 */
class MacAddress extends Model
{
    /** @use HasFactory<MacAddressFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'mac_address',
        'user_id',
        'source',
        'description',
    ];

    public function getRouteKeyName(): string
    {
        return 'mac_address';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $field ??= $this->getRouteKeyName();

        return static::where($field, self::normalize((string) $value))->first()
            ?? static::create([
                'mac_address' => self::normalize((string) $value),
                'source' => 'discovery',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'mac_address' => NormalizeMacAddress::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<IpAddress, $this, IpAddressMacAddress> */
    public function ipAddresses(): BelongsToMany
    {
        return $this->belongsToMany(IpAddress::class, 'ip_address_mac_address')
            ->using(IpAddressMacAddress::class)
            ->withPivot('source', 'last_seen_at')
            ->withTimestamps();
    }

    /** @return HasMany<DhcpLease, $this> */
    public function dhcpLeases(): HasMany
    {
        return $this->hasMany(DhcpLease::class);
    }

    /** @return BelongsToMany<SwitchPort, $this> */
    public function switchPorts(): BelongsToMany
    {
        return $this->belongsToMany(SwitchPort::class, 'switch_port_macs')
            ->withPivot('vlan', 'last_seen_at', 'mac_address');
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public static function normalize(string $mac): string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        return implode(':', str_split($hex, 2));
    }

    public function currentIp(): ?IpAddress
    {
        return $this->ipAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->first();
    }

    public function currentHostname(): ?string
    {
        $lease = $this->dhcpLeases()
            ->whereNotNull('hostname')
            ->latest()
            ->first();

        return $lease?->hostname;
    }
}
