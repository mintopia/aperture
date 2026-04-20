<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchPortConfig extends Model
{
    /** @use HasFactory<SwitchPortConfigFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_port_id',
        'config_text',
        'config_hash',
        'interface_output',
        'last_fetched_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function switchPort(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }
}
