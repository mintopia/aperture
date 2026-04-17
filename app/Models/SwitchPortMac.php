<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortMacFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchPortMac extends Model
{
    /** @use HasFactory<SwitchPortMacFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_port_id',
        'mac_address',
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
}
