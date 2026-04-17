<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchSyncRun extends Model
{
    /** @use HasFactory<SwitchSyncRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_config_id',
        'status',
        'started_at',
        'finished_at',
        'error',
        'ports_created',
        'ports_updated',
        'macs_created',
        'macs_updated',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ports_created' => 'integer',
            'ports_updated' => 'integer',
            'macs_created' => 'integer',
            'macs_updated' => 'integer',
        ];
    }

    /** @return BelongsTo<SwitchConfig, $this> */
    public function switchConfig(): BelongsTo
    {
        return $this->belongsTo(SwitchConfig::class);
    }
}
