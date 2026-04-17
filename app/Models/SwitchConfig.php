<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $ports_up_count
 * @property-read int $ports_down_count
 * @property-read int $ports_error_count
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
