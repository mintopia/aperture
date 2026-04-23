<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\NormalizeMacAddress;
use Database\Factories\MacAddressFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $mac_address
 * @property int|null $user_id
 * @property string $source
 * @property bool $allowed
 * @property string|null $description
 * @property Carbon|null $allowed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, IpAddress> $ipAddresses
 * @property-read int|null $ip_addresses_count
 * @property-read User|null $user
 *
 * @method static \Database\Factories\MacAddressFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereAllowed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereAllowedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereMacAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MacAddress whereUserId($value)
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
        'allowed',
        'description',
        'allowed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'mac_address' => NormalizeMacAddress::class,
            'allowed' => 'boolean',
            'allowed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
