<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperUserIpAddress
 */
class UserIpAddress extends Model
{
    use HasFactory;

    protected $casts = [
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ip(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }
}
