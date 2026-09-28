<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of Setting
 *
 * @extends Builder<TModel>
 */
class SettingBuilder extends Builder
{
    public function update(array $values): int
    {
        return $this->flushAfter(fn (): int => parent::update($values));
    }

    public function delete(): mixed
    {
        return $this->flushAfter(fn (): mixed => parent::delete());
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return $this->flushAfter(fn (): int => parent::upsert($values, $uniqueBy, $update));
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return $this->flushAfter(fn (): bool => $this->toBase()->insert($values));
    }

    private function flushAfter(callable $write): mixed
    {
        try {
            return $write();
        } finally {
            Setting::flushCache();
        }
    }
}
