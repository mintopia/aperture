<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin IdeHelperUserIpAddress
 *
 * @property int $id
 * @property int $user_id
 * @property int $ip_address_id
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IpAddress $ip
 * @property-read User $user
 *
 * @method static Builder<static>|UserIpAddress newModelQuery()
 * @method static Builder<static>|UserIpAddress newQuery()
 * @method static Builder<static>|UserIpAddress query()
 * @method static Builder<static>|UserIpAddress whereCreatedAt($value)
 * @method static Builder<static>|UserIpAddress whereId($value)
 * @method static Builder<static>|UserIpAddress whereIpAddressId($value)
 * @method static Builder<static>|UserIpAddress whereLastSeenAt($value)
 * @method static Builder<static>|UserIpAddress whereUpdatedAt($value)
 * @method static Builder<static>|UserIpAddress whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserIpAddress extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $casts = [
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<IpAddress, $this> */
    public function ip(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }
}
