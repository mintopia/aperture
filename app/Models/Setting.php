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
use Illuminate\Support\Facades\Cache;

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

    public const CACHE_KEY = 'settings.all';

    protected static function booted(): void
    {
        static::saved(static fn () => static::flushCache());
        static::deleted(static fn () => static::flushCache());
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return SettingBuilder<static>
     */
    public function newEloquentBuilder($query): SettingBuilder
    {
        return new SettingBuilder($query);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allCached(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, static fn (): array => Setting::query()
            ->get()
            ->mapWithKeys(static fn (Setting $setting): array => [$setting->code => $setting->value])
            ->all());
    }

    public static function get(string $code, mixed $default = null): mixed
    {
        $all = static::allCached();

        return array_key_exists($code, $all) ? $all[$code] : $default;
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
