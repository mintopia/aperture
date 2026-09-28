<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $password
 * @property string|null $private_key
 * @property string|null $passphrase
 * @property string|null $host_key
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
 * @method static SwitchConfigFactory factory($count = null, $state = [])
 * @method static Builder<static>|SwitchConfig newModelQuery()
 * @method static Builder<static>|SwitchConfig newQuery()
 * @method static Builder<static>|SwitchConfig query()
 * @method static Builder<static>|SwitchConfig whereCreatedAt($value)
 * @method static Builder<static>|SwitchConfig whereEnablePassword($value)
 * @method static Builder<static>|SwitchConfig whereEnabled($value)
 * @method static Builder<static>|SwitchConfig whereHostname($value)
 * @method static Builder<static>|SwitchConfig whereId($value)
 * @method static Builder<static>|SwitchConfig whereName($value)
 * @method static Builder<static>|SwitchConfig wherePassword($value)
 * @method static Builder<static>|SwitchConfig wherePort($value)
 * @method static Builder<static>|SwitchConfig whereTimeout($value)
 * @method static Builder<static>|SwitchConfig whereType($value)
 * @method static Builder<static>|SwitchConfig whereUpdatedAt($value)
 * @method static Builder<static>|SwitchConfig whereUsername($value)
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name',
    'hostname',
    'type',
    'username',
    'password',
    'enable_password',
    'private_key',
    'passphrase',
    'host_key',
    'enabled',
    'port',
    'timeout',
])]
#[Hidden(['password', 'enable_password', 'private_key', 'passphrase'])]
class SwitchConfig extends Model
{
    /** @use HasFactory<SwitchConfigFactory> */
    use HasFactory;

    public static function defaultFallback(): self
    {
        return new self([
            'name' => 'Default Cisco Switch',
            'hostname' => (string) config('aperture.cisco.hostname', ''),
            'type' => 'cisco',
            'username' => (string) config('aperture.cisco.username', ''),
            'password' => (string) config('aperture.cisco.password', ''),
            'enable_password' => (string) config('aperture.cisco.enablePassword', ''),
            'enabled' => true,
            'port' => 22,
            'timeout' => (int) config('aperture.cisco.timeout', 5),
        ]);
    }

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
            'has_password' => filled($this->password),
            'has_private_key' => $this->usesPrivateKey(),
            'has_passphrase' => filled($this->passphrase),
            'host_key' => $this->host_key,
            'host_key_fingerprint' => $this->hostKeyFingerprint(),
            'enabled' => $this->enabled,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'last_synced_at' => $this->relationLoaded('latestSyncRun') ? $this->latestSyncRun?->finished_at : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function usesPrivateKey(): bool
    {
        return filled($this->private_key);
    }

    public function hostKeyFingerprint(): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $this->host_key));
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;

        return $blob === false ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
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
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'enabled' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
        ];
    }
}
