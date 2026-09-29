<?php

declare(strict_types=1);

namespace App\Support;

class SearchHelper
{
    /**
     * Convert user input into a SQL LIKE pattern.
     *
     * - Escapes SQL wildcards (% and _) in the input
     * - Maps user-friendly * wildcards to SQL %
     * - If no * wildcards are present, wraps in %..% for contains-style matching
     */
    public static function toLikePattern(string $input): string
    {
        $hasWildcard = str_contains($input, '*');

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $input);

        $escaped = str_replace('*', '%', $escaped);

        if (! $hasWildcard) {
            $escaped = '%'.$escaped.'%';
        }

        return $escaped;
    }
}
