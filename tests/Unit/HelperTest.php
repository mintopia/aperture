<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Helper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase
{
    public function test_human_size_zero(): void
    {
        $this->assertEquals('0.00 B', Helper::humanSize(0));
    }

    public function test_human_size_bytes(): void
    {
        $this->assertEquals('500 B', Helper::humanSize(500));
    }

    public function test_human_size_kilobytes(): void
    {
        $this->assertEquals('1 KB', Helper::humanSize(1024));
    }

    public function test_human_size_megabytes(): void
    {
        $this->assertEquals('1 MB', Helper::humanSize(1048576));
    }

    public function test_human_size_gigabytes(): void
    {
        $this->assertEquals('1 GB', Helper::humanSize(1073741824));
    }
}
