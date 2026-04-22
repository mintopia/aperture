<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class UserBandwidth
{
    /**
     * @param  array<int, string>  $timestamps
     * @param  array<int, float>  $download
     * @param  array<int, float>  $upload
     */
    public function __construct(
        public int $received,
        public int $sent,
        public array $timestamps,
        public array $download,
        public array $upload,
    ) {}
}
