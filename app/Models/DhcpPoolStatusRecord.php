<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpPoolStatusRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $integration
 * @property string $address_family
 * @property string $total
 * @property string $used
 * @property string $available
 * @property string $utilisation
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DhcpPoolStatusRecord extends Model
{
    /** @use HasFactory<DhcpPoolStatusRecordFactory> */
    use HasFactory;

    protected $table = 'dhcp_pool_statuses';

    /** @var list<string> */
    protected $fillable = [
        'integration',
        'address_family',
        'total',
        'used',
        'available',
        'utilisation',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
