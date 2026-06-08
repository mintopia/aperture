<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpSnoopingObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $switch_config_id
 * @property int $vlan
 * @property string $ip
 * @property string $mac
 * @property string|null $interface
 * @property Carbon|null $expires_at
 * @property Carbon $observed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DhcpSnoopingObservation extends Model
{
    /** @use HasFactory<DhcpSnoopingObservationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_config_id',
        'vlan',
        'ip',
        'mac',
        'interface',
        'expires_at',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SwitchConfig, $this> */
    public function switchConfig(): BelongsTo
    {
        return $this->belongsTo(SwitchConfig::class);
    }
}
