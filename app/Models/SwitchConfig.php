<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SwitchConfig extends Model
{
    /** @use HasFactory<SwitchConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'hostname',
        'type',
        'username',
        'password',
        'enable_password',
        'enabled',
        'port',
        'timeout',
    ];

    protected $hidden = ['password', 'enable_password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'enable_password' => 'encrypted',
            'enabled' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
        ];
    }
}
