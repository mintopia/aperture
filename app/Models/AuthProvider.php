<?php

namespace App\Models;

use App\Services\Interfaces\AuthBackendInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|AuthProvider newModelQuery()
 * @method static Builder|AuthProvider newQuery()
 * @method static Builder|AuthProvider query()
 * @method static Builder|AuthProvider whereClass($value)
 * @method static Builder|AuthProvider whereClientId($value)
 * @method static Builder|AuthProvider whereClientSecret($value)
 * @method static Builder|AuthProvider whereCode($value)
 * @method static Builder|AuthProvider whereCreatedAt($value)
 * @method static Builder|AuthProvider whereEnabled($value)
 * @method static Builder|AuthProvider whereId($value)
 * @method static Builder|AuthProvider whereName($value)
 * @method static Builder|AuthProvider whereUpdatedAt($value)
 *
 * @property-read Collection<int, UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @mixin \Eloquent
 * @mixin IdeHelperAuthProvider
 */
class AuthProvider extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $casts = [
        'client_secret' => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
    ];

    /** @return HasMany<UserAuthentication, $this> */
    public function authentications(): HasMany
    {
        return $this->hasMany(UserAuthentication::class, 'auth_provider_id');
    }

    /** @return HasManyThrough<User, UserAuthentication, $this> */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, UserAuthentication::class);
    }

    public function getBackend(): AuthBackendInterface
    {
        /** @var class-string<AuthBackendInterface> $class */
        $class = $this->class;

        return new $class($this);
    }
}
