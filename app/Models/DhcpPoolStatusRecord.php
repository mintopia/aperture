<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpPoolStatusRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
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
#[Fillable([
    'integration',
    'address_family',
    'total',
    'used',
    'available',
    'utilisation',
    'synced_at',
])]
#[Table(name: 'dhcp_pool_statuses')]
class DhcpPoolStatusRecord extends Model
{
    /** @use HasFactory<DhcpPoolStatusRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
