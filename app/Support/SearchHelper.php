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

        // Escape SQL wildcards in user input
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $input);

        // Replace user-friendly * with SQL %
        $escaped = str_replace('*', '%', $escaped);

        // If no user wildcards, wrap in % for contains-style search
        if (! $hasWildcard) {
            $escaped = '%'.$escaped.'%';
        }

        return $escaped;
    }
}
