<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchConfigFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property-read int $ports_up_count
 * @property-read int $ports_down_count
 * @property-read int $ports_error_count
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property string $type
 * @property string $username
 * @property string $password
 * @property string|null $enable_password
 * @property bool $enabled
 * @property int $port
 * @property int $timeout
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SwitchSyncRun|null $latestSyncRun
 * @property-read Collection<int, SwitchPort> $switchPorts
 * @property-read int|null $switch_ports_count
 * @property-read Collection<int, SwitchSyncRun> $switchSyncRuns
 * @property-read int|null $switch_sync_runs_count
 *
 * @method static \Database\Factories\SwitchConfigFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereEnablePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereHostname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwitchConfig whereUsername($value)
 *
 * @mixin \Eloquent
 */
class SwitchConfig extends Model
{
    /** @use HasFactory<SwitchConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'hostname',
        'type',
        'username',
        'password',
        'enable_password',
        'enabled',
        'port',
        'timeout',
    ];

    protected $hidden = ['password', 'enable_password'];

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return HasMany<SwitchPort, $this> */
    public function switchPorts(): HasMany
    {
        return $this->hasMany(SwitchPort::class);
    }

    /** @return HasMany<SwitchSyncRun, $this> */
    public function switchSyncRuns(): HasMany
    {
        return $this->hasMany(SwitchSyncRun::class);
    }

    /** @return HasOne<SwitchSyncRun, $this> */
    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SwitchSyncRun::class)->latestOfMany();
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'enable_password' => 'encrypted',
            'enabled' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
        ];
    }
}
