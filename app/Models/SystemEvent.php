<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SystemEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
class SystemEvent extends Model
{
    /** @use HasFactory<SystemEventFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'level',
        'message',
        'data',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
