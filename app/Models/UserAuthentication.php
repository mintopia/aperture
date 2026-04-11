<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\UserAuthentication
 *
 * @property int $id
 * @property int $user_id
 * @property int $authprovider_id
 * @property string $external_id
 * @property string $access_token
 * @property string $refresh_token
 * @property string $token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|UserAuthentication newModelQuery()
 * @method static Builder|UserAuthentication newQuery()
 * @method static Builder|UserAuthentication query()
 * @method static Builder|UserAuthentication whereAccessToken($value)
 * @method static Builder|UserAuthentication whereAuthProviderId($value)
 * @method static Builder|UserAuthentication whereCreatedAt($value)
 * @method static Builder|UserAuthentication whereExternalId($value)
 * @method static Builder|UserAuthentication whereId($value)
 * @method static Builder|UserAuthentication whereRefreshToken($value)
 * @method static Builder|UserAuthentication whereTokenExpiresAt($value)
 * @method static Builder|UserAuthentication whereUpdatedAt($value)
 * @method static Builder|UserAuthentication whereUserId($value)
 *
 * @property-read AuthProvider $provider
 * @property-read User $user
 * @property int $auth_provider_id
 *
 * @mixin \Eloquent
 * @mixin IdeHelperUserAuthentication
 */
class UserAuthentication extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /** @return BelongsTo<AuthProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AuthProvider::class, 'auth_provider_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
