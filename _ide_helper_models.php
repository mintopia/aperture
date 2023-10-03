<?php

// @formatter:off
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * App\Models\AuthProvider
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $class
 * @property string|null $client_id
 * @property mixed|null $client_secret
 * @property int $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
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
 * @mixin \Eloquent
 */
	class IdeHelperAuthProvider {}
}

namespace App\Models{
/**
 * App\Models\IpAddress
 *
 * @property int $id
 * @property string $address
 * @property int $allowed
 * @property int $limited
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserIpAddress> $user
 * @property-read int|null $user_count
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereAllowed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereLimited($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IpAddress whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	class IdeHelperIpAddress {}
}

namespace App\Models{
/**
 * App\Models\Role
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	class IdeHelperRole {}
}

namespace App\Models{
/**
 * App\Models\Setting
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property mixed|null|null $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Setting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting query()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereValue($value)
 * @mixin \Eloquent
 */
	class IdeHelperSetting {}
}

namespace App\Models{
/**
 * App\Models\User
 *
 * @property int $id
 * @property string $nickname
 * @property string|null $email
 * @property int $blocked
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserAuthentication> $authentications
 * @property-read int|null $authentications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserIpAddress> $ips
 * @property-read int|null $ips_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereBlocked($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	class IdeHelperUser {}
}

namespace App\Models{
/**
 * App\Models\UserAuthentication
 *
 * @property int $id
 * @property int $user_id
 * @property int $auth_provider_id
 * @property string $external_id
 * @property mixed|null $access_token
 * @property mixed|null $refresh_token
 * @property string|null $token_expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\AuthProvider $provider
 * @property-read \App\Models\User $user
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
 * @mixin \Eloquent
 */
	class IdeHelperUserAuthentication {}
}

namespace App\Models{
/**
 * App\Models\UserIpAddress
 *
 * @property int $id
 * @property int $user_id
 * @property int $ipaddress_id
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\IpAddress|null $ip
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereIpaddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserIpAddress whereUserId($value)
 * @mixin \Eloquent
 */
	class IdeHelperUserIpAddress {}
}

