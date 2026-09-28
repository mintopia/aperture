<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntegrationConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $id
 * @property string $integration
 * @property string $key
 * @property mixed|null $value
 * @property bool $encrypted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static IntegrationConfigFactory factory($count = null, $state = [])
 * @method static Builder<static>|IntegrationConfig newModelQuery()
 * @method static Builder<static>|IntegrationConfig newQuery()
 * @method static Builder<static>|IntegrationConfig query()
 * @method static Builder<static>|IntegrationConfig whereCreatedAt($value)
 * @method static Builder<static>|IntegrationConfig whereEncrypted($value)
 * @method static Builder<static>|IntegrationConfig whereId($value)
 * @method static Builder<static>|IntegrationConfig whereIntegration($value)
 * @method static Builder<static>|IntegrationConfig whereKey($value)
 * @method static Builder<static>|IntegrationConfig whereUpdatedAt($value)
 * @method static Builder<static>|IntegrationConfig whereValue($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['integration', 'key', 'value', 'encrypted'])]
class IntegrationConfig extends Model
{
    /** @use HasFactory<IntegrationConfigFactory> */
    use HasFactory;

    /**
     * Hardcoded fallback for sensitive field keys.
     *
     * @deprecated Use {@see encryptedKeys()} instead, which derives keys from config/integrations.php.
     *
     * @var list<string>
     */
    public const ENCRYPTED_KEYS = ['api_key', 'api_token', 'password', 'secret', 'key', 'client_secret'];

    /**
     * Get all field keys that should be encrypted, derived from config/integrations.php.
     * Any field with type 'password' is considered sensitive and will be encrypted.
     * Falls back to ENCRYPTED_KEYS for keys not present in config.
     *
     * @return list<string>
     */
    public static function encryptedKeys(): array
    {
        return once(function (): array {
            /** @var array<string, array{fields?: array<string, array{type?: string}>}> $integrations */
            $integrations = config('integrations', []);

            $fromConfig = collect($integrations)
                ->flatMap(fn (array $integration): array => $integration['fields'] ?? [])
                ->filter(fn (array $field): bool => ($field['type'] ?? '') === 'password')
                ->keys()
                ->unique()
                ->values()
                ->all();

            return array_values(array_unique(array_merge($fromConfig, self::ENCRYPTED_KEYS)));
        });
    }

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }

    private bool $valuePlain = false;

    protected static function booted(): void
    {
        static::saving(function (self $config): void {
            $config->sealPlainValue();
        });
    }

    /** @return Attribute<mixed, mixed> */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: function (?string $raw): mixed {
                if ($raw === null) {
                    return null;
                }

                $decoded = json_decode($raw, true);
                $val = $decoded['v'] ?? null;

                if ($val !== null && $this->encrypted && ! $this->valuePlain) {
                    return decrypt($val);
                }

                return $val;
            },
            set: function (mixed $value): array {
                $this->valuePlain = $value !== null;

                return ['value' => $value === null ? null : json_encode(['v' => $value])];
            },
        );
    }

    private function sealPlainValue(): void
    {
        if (! $this->valuePlain) {
            return;
        }

        $this->valuePlain = false;

        if (! $this->encrypted) {
            return;
        }

        /** @var array{v: mixed} $decoded */
        $decoded = json_decode((string) $this->attributes['value'], true);
        $this->attributes['value'] = json_encode(['v' => encrypt($decoded['v'])]);
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

    /**
     * @return array<string, mixed>
     */
    public static function safeGetAll(string $integration): array
    {
        try {
            return static::getAll($integration);
        } catch (Throwable) {
            return [];
        }
    }
}
