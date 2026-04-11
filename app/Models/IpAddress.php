<?php

namespace App\Models;

use App\Jobs\IpAddressAction;
use App\Models\Traits\ToString;
use App\Services\CiscoService;
use App\Services\Firewalls\OpnSense;
use App\Services\NtopNgService;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * App\Models\IpAddress
 *
 * @property int $id
 * @property string $address
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $mac
 * @property-read object{switch: string, interface: string, status: string, adminStatus: string, speed: int}|null $port
 * @property-read \Illuminate\Support\Carbon|null $portUpdatedAt
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

    /** @var array<int, stdClass>|null */
    protected ?array $lnms = null;

    protected string $stringDescriptionProperty = 'address';

    public function __get($name)
    {
        switch ($name) {
            case 'mac':
            case 'port':
            case 'portUpdatedAt':
                $lnms = $this->getLNMSData();

                return $lnms[0]->{$name} ?? null;
            default:
                return parent::__get($name);
        }
    }

    /** @return HasMany<UserIpAddress, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(UserIpAddress::class, 'ip_address_id')->orderBy('last_seen_at', 'desc');
    }

    public function shutPort(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'shutPort');

            return;
        }

        if ($this->port === null) {
            return;
        }

        $cisco = new CiscoService($this->port->switch);
        $cisco->shutInterface($this->port->interface);
    }

    public function unshutPort(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'unshutPort');

            return;
        }

        if ($this->port === null) {
            return;
        }

        $cisco = new CiscoService($this->port->switch);
        $cisco->unshutInterface($this->port->interface);
    }

    /**
     * @return array<int, stdClass>
     */
    public function getLNMSData(): array
    {
        if (! config('aperture.lnms.enabled')) {
            return [];
        }

        if ($this->lnms !== null) {
            return $this->lnms;
        }

        $query = '
            SELECT
                ipv4_mac.ipv4_address AS `ip`,
                ipv4_mac.mac_address AS `mac`,
                devices.hostname AS `switch`,
                ports.ifName AS `interface`,
                ports_fdb.updated_at AS `updatedAt`,
                ports.ifOperStatus AS `status`,
                ports.ifAdminStatus AS `adminStatus`,
                ports.ifSpeed AS `speed`
            FROM ipv4_mac
            INNER JOIN ports_fdb ON ports_fdb.mac_address = ipv4_mac.mac_address
            INNER JOIN ports ON ports.port_id = ports_fdb.port_id
            INNER JOIN devices ON devices.device_id = ports.device_id
            WHERE
                ipv4_address = :ip;
        ';
        $bindings = [
            'ip' => $this->address,
        ];
        $result = DB::connection('lnms')->select($query, $bindings);
        $this->lnms = array_map(function ($row) {
            return (object) [
                'mac' => $row->mac,
                'port' => (object) [
                    'switch' => $row->switch,
                    'interface' => $row->interface,
                    'status' => $row->status,
                    'adminStatus' => $row->adminStatus,
                    'speed' => $row->speed,
                ],
                'portUpdatedAt' => new Carbon($row->updatedAt),
            ];
        }, $result);
        usort($this->lnms, function ($alpha, $bravo): int {
            return $bravo->portUpdatedAt->timestamp <=> $alpha->portUpdatedAt->timestamp;
        });

        return $this->lnms;
    }

    public function limit(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'limit');

            return;
        }

        $opnsense = new OpnSense;
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

        $opnsense = new OpnSense;
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

        $opnsense = new OpnSense;
        $opnsense->updateIp($this->address, (string) $description);

        $this->allowed = true;
        $this->save();
    }

    public function deny(bool $queue = false): void
    {
        if ($queue) {
            IpAddressAction::dispatch($this, 'deny');

            return;
        }

        $opnsense = new OpnSense;
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
