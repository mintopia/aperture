<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IpAddressMacAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ip_address_id
 * @property int $mac_address_id
 * @property string $source
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IpAddressMacAddress extends Pivot
{
    /** @use HasFactory<IpAddressMacAddressFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'ip_address_mac_address';

    /** @var list<string> */
    protected $fillable = [
        'ip_address_id',
        'mac_address_id',
        'source',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
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
