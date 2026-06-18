<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpRangeRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $integration
 * @property string $interface
 * @property string $type
 * @property string $subnet
 * @property string $range_from
 * @property string $range_to
 * @property string|null $prefix
 * @property string|null $gateway
 * @property string|null $description
 * @property string|null $total_addresses
 * @property string|null $used_addresses
 * @property string|null $utilisation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DhcpRangeRecord extends Model
{
    /** @use HasFactory<DhcpRangeRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'integration',
        'interface',
        'type',
        'subnet',
        'range_from',
        'range_to',
        'prefix',
        'gateway',
        'description',
        'total_addresses',
        'used_addresses',
        'utilisation',
    ];
}
