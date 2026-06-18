<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SettingValue;
use App\Models\Traits\ToString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\Setting
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Setting newModelQuery()
 * @method static Builder|Setting newQuery()
 * @method static Builder|Setting query()
 * @method static Builder|Setting whereAllowed($value)
 * @method static Builder|Setting whereCode($value)
 * @method static Builder|Setting whereCreatedAt($value)
 * @method static Builder|Setting whereDescription($value)
 * @method static Builder|Setting whereId($value)
 * @method static Builder|Setting whereLimited($value)
 * @method static Builder|Setting whereName($value)
 * @method static Builder|Setting whereUpdatedAt($value)
 * @method static Builder|Setting whereValue($value)
 *
 * @mixin IdeHelperSetting
 *
 * @property string $type
 *
 * @method static Builder<static>|Setting whereType($value)
 *
 * @mixin \Eloquent
 */
class Setting extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ToString;

    protected $fillable = [
        'code',
        'name',
        'value',
    ];

    protected $casts = [
        'value' => SettingValue::class,
    ];

    public static function get(string $code, mixed $default = null): mixed
    {
        $setting = Setting::whereCode($code)->first();
        if ($setting) {
            return $setting->value;
        }

        return $default;
    }

    /**
     * Set the value of a setting by code, creating it if it does not exist.
     *
     * @param  string  $code  The unique identifier for the setting.
     * @param  string  $name  The human-readable name; only used when creating a new setting
     *                        (ignored when updating an existing one).
     * @param  mixed  $value  The value to store; may be null.
     */
    public static function set(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
    }
}
