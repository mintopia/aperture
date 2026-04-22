<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserParameterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property array<array-key, mixed>|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Database\Factories\UserParameterFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserParameter whereValue($value)
 *
 * @mixin \Eloquent
 */
class UserParameter extends Model
{
    /** @use HasFactory<UserParameterFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
