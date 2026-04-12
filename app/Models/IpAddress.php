<?php

namespace App\Models;

use App\Jobs\IpAddressAction;
use App\Models\Traits\ToString;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NtopNgService;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $mac
 * @property-read object{switch: string, interface: string, status: string, adminStatus: string, speed: int}|null $port
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

    protected ?stdClass $portInfoCache = null;

    protected bool $portInfoResolved = false;

    protected string $stringDescriptionProperty = 'address';

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

    public function getPortInfo(): ?stdClass
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

            $detail = $inventory->getPortDetail($resolved['port']);
            if ($detail === null) {
                return null;
            }

            $this->portInfoCache = (object) [
                'switch' => $detail['hostname'],
                'interface' => $detail['interface'],
                'status' => $detail['status'],
                'adminStatus' => $detail['adminStatus'],
                'speed' => $detail['speed'],
            ];

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

        $portInfo = $this->getPortInfo();
        if (! $portInfo instanceof stdClass) {
            return;
        }

        /** @var NetworkSwitchInterface $switch */
        $switch = app(NetworkSwitchInterface::class);
        $switch->shutdownPort($portInfo->interface);
    }

    public function unshutPort(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'unshutPort');

            return;
        }

        $portInfo = $this->getPortInfo();
        if (! $portInfo instanceof stdClass) {
            return;
        }

        /** @var NetworkSwitchInterface $switch */
        $switch = app(NetworkSwitchInterface::class);
        $switch->enablePort($portInfo->interface);
    }

    public function limit(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'limit');

            return;
        }

        $opnsense = app(FirewallBackendInterface::class);
        $opnsense->limitIp($this->address);

        $this->limited = true;
        $this->save();
    }

    public function unlimit(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'unlimit');

            return;
        }

        $opnsense = app(FirewallBackendInterface::class);
        $opnsense->unlimitIp($this->address);

        $this->limited = false;
        $this->save();
    }

    public function allow(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'allow');

            return;
        }

        $description = $this->comment;
        /** @var UserIpAddress|null $userIp */
        $userIp = $this->users()->first();
        if ($userIp && $userIp->user) {
            $description = $userIp->user->nickname;
        }

        $opnsense = app(FirewallBackendInterface::class);
        $opnsense->updateIp($this->address, (string) $description);

        $this->allowed = true;
        $ttl = config('aperture.session.ttl');
        if ($ttl) {
            $this->expires_at = now()->addMinutes((int) $ttl);
        }

        try {
            $resolver = app(MacAddressResolverInterface::class);
            $mac = $resolver->resolveIpToMac($this->address);
            if ($mac !== null) {
                $macAddress = MacAddress::firstOrCreate(
                    ['mac_address' => $mac],
                    ['source' => 'auth', 'allowed' => true, 'allowed_at' => now()],
                );
                $this->mac_address_id = (int) $macAddress->id; // @phpstan-ignore assign.propertyType
                if (! $macAddress->allowed) {
                    $macAddress->allowed = true;
                    $macAddress->allowed_at = now();
                    $macAddress->save();
                }

                if ($macAddress->user_id === null && $userIp?->user) {
                    $macAddress->user_id = (int) $userIp->user->id; // @phpstan-ignore assign.propertyType
                    $macAddress->save();
                }
            }
        } catch (Throwable) {
            // MAC resolution is best-effort — never block the allow flow
        }

        $this->save();
    }

    public function deny(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'deny');

            return;
        }

        $opnsense = app(FirewallBackendInterface::class);
        $opnsense->removeIp($this->address);

        $this->allowed = false;
        $this->save();
    }

    public function updateUsage(): void
    {
        try {
            $stats = $this->getStats();
            $attr = 'bytes.rcvd';
            $this->received = $stats->rsp->$attr;
            $attr = 'bytes.sent';
            $this->sent = $stats->rsp->$attr;
            $this->save();
        } catch (ClientException $clientException) {
            // Do Nothing
        }
    }

    public function getStats(): stdClass
    {
        /** @var NtopNgService $ntopng */
        $ntopng = resolve(NtopNgService::class);

        return $ntopng->getStats($this->address);
    }
}
