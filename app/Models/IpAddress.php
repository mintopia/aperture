<?php

namespace App\Models;

use App\Jobs\IpAddressAction;
use App\Models\Traits\ToString;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\IpAddressActionService;
use App\Services\ValueObjects\PortDetail;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use stdClass;
use Throwable;

/**
 * App\Models\IpAddress
 *
 * @property int $id
 * @property string $address
 * @property bool $internet_enabled
 * @property bool $rate_limit_enabled
 * @property bool $dns_filtering_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $mac
 * @property-read PortDetail|null $port
 * @property-read null $portUpdatedAt
 *
 * @method static Builder|IpAddress newModelQuery()
 * @method static Builder|IpAddress newQuery()
 * @method static Builder|IpAddress query()
 * @method static Builder|IpAddress whereAddress($value)
 * @method static Builder|IpAddress whereCreatedAt($value)
 * @method static Builder|IpAddress whereId($value)
 * @method static Builder|IpAddress whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 * @mixin IdeHelperIpAddress
 */
class IpAddress extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ToString;

    protected ?PortDetail $portInfoCache = null;

    protected bool $portInfoResolved = false;

    protected string $stringDescriptionProperty = 'address';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internet_enabled' => 'boolean',
            'rate_limit_enabled' => 'boolean',
            'dns_filtering_enabled' => 'boolean',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'address';
    }

    public function __get($name)
    {
        switch ($name) {
            case 'mac':
                return $this->macAddress?->mac_address;
            case 'port':
                return $this->getPortInfo();
            case 'portUpdatedAt':
                return null;
            default:
                return parent::__get($name);
        }
    }

    /** @return HasMany<UserIpAddress, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(UserIpAddress::class, 'ip_address_id')->orderBy('last_seen_at', 'desc');
    }

    /** @return BelongsTo<MacAddress, $this> */
    public function macAddress(): BelongsTo
    {
        return $this->belongsTo(MacAddress::class);
    }

    public function getPortInfo(): ?PortDetail
    {
        if ($this->portInfoResolved) {
            return $this->portInfoCache;
        }

        $this->portInfoResolved = true;

        try {
            /** @var NetworkInventoryInterface $inventory */
            $inventory = app(NetworkInventoryInterface::class);
            $resolved = $inventory->resolveIpToPort($this->address);
            if ($resolved === null) {
                return null;
            }

            $detail = $inventory->getPortDetail($resolved->port);
            if ($detail === null) {
                return null;
            }

            $this->portInfoCache = $detail;

            return $this->portInfoCache;
        } catch (Throwable) {
            return null;
        }
    }

    public function shutPort(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'shutPort');

            return;
        }

        app(IpAddressActionService::class)->shutPort($this);
    }

    public function unshutPort(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'unshutPort');

            return;
        }

        app(IpAddressActionService::class)->unshutPort($this);
    }

    public function updateUsage(): void
    {
        try {
            app(IpAddressActionService::class)->updateUsage($this);
        } catch (ClientException $clientException) {
            // Do Nothing
        }
    }

    public function getStats(): stdClass
    {
        return app(IpAddressActionService::class)->getStats($this);
    }
}
