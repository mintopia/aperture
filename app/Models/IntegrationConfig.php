<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntegrationConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationConfig extends Model
{
    /** @use HasFactory<IntegrationConfigFactory> */
    use HasFactory;

    /** @var list<string> */
    public const ENCRYPTED_KEYS = ['api_key', 'password', 'secret', 'key'];

    protected $fillable = ['integration', 'key', 'value', 'encrypted'];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }

    public function getValueAttribute(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $val = $decoded['v'] ?? null;

        if ($this->encrypted && $val !== null) {
            return decrypt($val);
        }

        return $val;
    }

    public function setValueAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['value'] = null;

            return;
        }

        $stored = $this->encrypted ? encrypt($value) : $value;
        $this->attributes['value'] = json_encode(['v' => $stored]);
    }

    public static function getValue(string $integration, string $key, mixed $default = null): mixed
    {
        $config = static::where('integration', $integration)->where('key', $key)->first();

        if ($config === null) {
            return $default;
        }

        return $config->value;
    }

    public static function setValue(string $integration, string $key, mixed $value, bool $encrypted = false): void
    {
        $config = static::firstOrNew(['integration' => $integration, 'key' => $key]);
        $config->encrypted = $encrypted;
        $config->value = $value;
        $config->save();
    }

    public static function getWithFallback(string $integration, string $key, mixed $default = null): mixed
    {
        return static::getValue($integration, $key) ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAll(string $integration): array
    {
        return static::where('integration', $integration)
            ->get()
            ->mapWithKeys(fn (self $c): array => [$c->key => $c->value])
            ->toArray();
    }
}
