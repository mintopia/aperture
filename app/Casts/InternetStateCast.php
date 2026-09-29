<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\InternetState;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<InternetState, InternetState|bool|null>
 */
class InternetStateCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): InternetState
    {
        return InternetState::fromColumn($value === null ? null : (bool) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        $state = $value instanceof InternetState ? $value : InternetState::fromColumn($value === null ? null : (bool) $value);
        $column = $state->toColumn();

        return $column === null ? null : (int) $column;
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return $value instanceof InternetState ? $value->toColumn() : $value;
    }
}
