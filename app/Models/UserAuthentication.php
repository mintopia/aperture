<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereAccessToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereAuthProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereRefreshToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereTokenExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserAuthentication whereUserId($value)
 * @property-read \App\Models\AuthProvider $provider
 * @property-read \App\Models\User $user
 * @property int $auth_provider_id
 * @mixin \Eloquent
 * @mixin IdeHelperUserAuthentication
 */
class UserAuthentication extends Model
{
    use HasFactory;

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AuthProvider::class, 'auth_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
