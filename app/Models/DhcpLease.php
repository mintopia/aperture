<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpLeaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ip_address_id
 * @property int $mac_address_id
 * @property string|null $hostname
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DhcpLease extends Model
{
    /** @use HasFactory<DhcpLeaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ip_address_id',
        'mac_address_id',
        'hostname',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IpAddress, $this> */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    /** @return BelongsTo<MacAddress, $this> */
    public function macAddress(): BelongsTo
    {
        return $this->belongsTo(MacAddress::class);
    }
}
