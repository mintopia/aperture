<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpSyncStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $integration
 * @property string $address_family
 * @property string $dataset
 * @property int $empty_count
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DhcpSyncState extends Model
{
    /** @use HasFactory<DhcpSyncStateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'integration',
        'address_family',
        'dataset',
        'empty_count',
        'last_attempt_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
