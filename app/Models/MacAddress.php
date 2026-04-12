<?php

namespace App\Models;

use App\Casts\NormalizeMacAddress;
use Database\Factories\MacAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MacAddress extends Model
{
    /** @use HasFactory<MacAddressFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'mac_address',
        'user_id',
        'source',
        'allowed',
        'description',
        'allowed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'mac_address' => NormalizeMacAddress::class,
            'allowed' => 'boolean',
            'allowed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
