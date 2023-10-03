<?php

namespace App\Models;

use App\Casts\SettingValue;
use App\Models\Enums\SettingValueType;
use App\Models\Traits\ToString;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Setting
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property mixed $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Setting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting query()
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereAllowed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereLimited($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Setting whereValue($value)
 * @mixin \Eloquent
 * @mixin IdeHelperSetting
 */
class Setting extends Model
{
    use HasFactory, ToString;

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
}
