<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchSyncRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $switch_config_id
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property int $ports_created
 * @property int $ports_updated
 * @property int $macs_created
 * @property int $macs_updated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SwitchConfig $switchConfig
 *
 * @method static SwitchSyncRunFactory factory($count = null, $state = [])
 * @method static Builder<static>|SwitchSyncRun newModelQuery()
 * @method static Builder<static>|SwitchSyncRun newQuery()
 * @method static Builder<static>|SwitchSyncRun query()
 * @method static Builder<static>|SwitchSyncRun whereCreatedAt($value)
 * @method static Builder<static>|SwitchSyncRun whereError($value)
 * @method static Builder<static>|SwitchSyncRun whereFinishedAt($value)
 * @method static Builder<static>|SwitchSyncRun whereId($value)
 * @method static Builder<static>|SwitchSyncRun whereMacsCreated($value)
 * @method static Builder<static>|SwitchSyncRun whereMacsUpdated($value)
 * @method static Builder<static>|SwitchSyncRun wherePortsCreated($value)
 * @method static Builder<static>|SwitchSyncRun wherePortsUpdated($value)
 * @method static Builder<static>|SwitchSyncRun whereStartedAt($value)
 * @method static Builder<static>|SwitchSyncRun whereStatus($value)
 * @method static Builder<static>|SwitchSyncRun whereSwitchConfigId($value)
 * @method static Builder<static>|SwitchSyncRun whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
