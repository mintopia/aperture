<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

final class Matcher
{
    /**
     * @return array{type: 'literal', value: string}
     */
    public static function literal(string $value): array
    {
        return ['type' => 'literal', 'value' => $value];
    }

    /**
     * @return array{type: 'regex', value: string}
     */
    public static function regex(string $pattern): array
    {
        return ['type' => 'regex', 'value' => $pattern];
    }
}
