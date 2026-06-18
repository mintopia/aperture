<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SafeCss implements ValidationRule
{
    /**
     * Dangerous CSS patterns that could enable injection attacks.
     *
     * @var list<string>
     */
    private const DANGEROUS_PATTERNS = [
        '/expression\s*\(/i',
        '/@import/i',
        '/url\s*\(\s*javascript\s*:/i',
        '/url\s*\(\s*data\s*:/i',
        '/-moz-binding/i',
        '/behavior\s*:/i',
        '/binding\s*\(/i',
        '/<script/i',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $fail('The :attribute contains potentially dangerous CSS patterns.');

                return;
            }
        }
    }

    /**
     * Check whether the given CSS string contains dangerous patterns.
     */
    public static function containsDangerousPatterns(string $css): bool
    {
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $css) === 1) {
                return true;
            }
        }

        return false;
    }
}
