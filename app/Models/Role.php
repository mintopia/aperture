<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ToString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\Role
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Role newModelQuery()
 * @method static Builder|Role newQuery()
 * @method static Builder|Role query()
 * @method static Builder|Role whereCode($value)
 * @method static Builder|Role whereCreatedAt($value)
 * @method static Builder|Role whereId($value)
 * @method static Builder|Role whereName($value)
 * @method static Builder|Role whereUpdatedAt($value)
 *
 * @mixin IdeHelperRole
 * @mixin \Eloquent
 */
class Role extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ToString;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
    ];
}
