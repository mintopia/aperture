<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $switch_config_id
 * @property string $port_name
 * @property string $port_number
 * @property string|null $switch_description
 * @property string|null $admin_notes
 * @property int|null $access_vlan
 * @property string|null $switchport_mode
 * @property string|null $speed
 * @property string $status
 * @property string $admin_status
 * @property string|null $duplex
 * @property string|null $poe_status
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SwitchPortConfig|null $config
 * @property-read SwitchConfig $switchConfig
 * @property-read SwitchPortConfig|null $switchPortConfig
 * @property-read Collection<int, SwitchPortMac> $switchPortMacs
 * @property-read int|null $switch_port_macs_count
 *
 * @method static \Database\Factories\SwitchPortFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereAccessVlan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereAdminNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereAdminStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereDuplex($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereLastSyncedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort wherePoeStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort wherePortName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort wherePortNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereSpeed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereSwitchConfigId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereSwitchDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereSwitchportMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchPort whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SwitchPort extends Model
{
    /** @use HasFactory<SwitchPortFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_config_id',
        'port_name',
        'port_number',
        'switch_description',
        'admin_notes',
        'access_vlan',
        'switchport_mode',
        'speed',
        'status',
        'admin_status',
        'duplex',
        'poe_status',
        'last_synced_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'access_vlan' => 'integer',
        ];
    }

    /** @return BelongsTo<SwitchConfig, $this> */
    public function switchConfig(): BelongsTo
    {
        return $this->belongsTo(SwitchConfig::class);
    }

    /** @return HasMany<SwitchPortMac, $this> */
    public function switchPortMacs(): HasMany
    {
        return $this->hasMany(SwitchPortMac::class);
    }

    /** @return HasOne<SwitchPortConfig, $this> */
    public function config(): HasOne
    {
        return $this->hasOne(SwitchPortConfig::class);
    }

    /** @return HasOne<SwitchPortConfig, $this> */
    public function switchPortConfig(): HasOne
    {
        return $this->config();
    }
}
