<?php

namespace App\Models;

use App\Services\Interfaces\AuthBackendInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * App\Models\AuthProvider
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $class
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property int $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider query()
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereClientSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AuthProvider whereUpdatedAt($value)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @mixin \Eloquent
 * @mixin IdeHelperAuthProvider
 */
class AuthProvider extends Model
{
    use HasFactory;

    protected $casts = [
        'client_secret' => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function authentications(): HasMany
    {
        return $this->hasMany(UserAuthentication::class, 'auth_provider_id');
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, UserAuthentication::class);
    }

    public function getBackend(): AuthBackendInterface
    {
        return new $this->class($this);
    }
}
