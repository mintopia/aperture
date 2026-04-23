<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortMacFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $switch_port_id
 * @property string $mac_address
 * @property int|null $mac_address_id
 * @property int|null $vlan
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SwitchPort $switchPort
 * @property-read MacAddress|null $macAddressRecord
 *
 * @method static SwitchPortMacFactory factory($count = null, $state = [])
 * @method static Builder<static>|SwitchPortMac newModelQuery()
 * @method static Builder<static>|SwitchPortMac newQuery()
 * @method static Builder<static>|SwitchPortMac query()
 * @method static Builder<static>|SwitchPortMac whereCreatedAt($value)
 * @method static Builder<static>|SwitchPortMac whereId($value)
 * @method static Builder<static>|SwitchPortMac whereLastSeenAt($value)
 * @method static Builder<static>|SwitchPortMac whereMacAddress($value)
 * @method static Builder<static>|SwitchPortMac whereSwitchPortId($value)
 * @method static Builder<static>|SwitchPortMac whereUpdatedAt($value)
 * @method static Builder<static>|SwitchPortMac whereVlan($value)
 *
 * @mixin \Eloquent
 */
class SwitchPortMac extends Model
{
    /** @use HasFactory<SwitchPortMacFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_port_id',
        'mac_address',
        'mac_address_id',
        'vlan',
        'last_seen_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'vlan' => 'integer',
        ];
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function switchPort(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }

    /** @return BelongsTo<MacAddress, $this> */
    public function macAddressRecord(): BelongsTo
    {
        return $this->belongsTo(MacAddress::class, 'mac_address_id');
    }
}
