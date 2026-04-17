<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
