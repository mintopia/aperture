<?php

declare(strict_types=1);

namespace App\Integration;

use App\Models\IntegrationConfig;
use Closure;
use Illuminate\Database\QueryException;

final class InstallGuard
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $default
     * @return T
     */
    public static function tolerateMissingTable(Closure $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if (self::isMissingTable($e)) {
                return $default;
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(string $integration): array
    {
        return self::tolerateMissingTable(fn (): array => IntegrationConfig::getAll($integration), []);
    }

    private static function isMissingTable(QueryException $e): bool
    {
        return in_array($e->getCode(), ['42S02', '42P01'], true)
            || str_contains($e->getMessage(), 'no such table');
    }
}
