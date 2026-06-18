<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

class ReconcileResult
{
    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     * @param  array<int, string>  $unchanged
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly array $added,
        public readonly array $removed,
        public readonly array $unchanged,
        public readonly array $errors,
    ) {}
}
