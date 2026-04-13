<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\IpAddressActionService;
use Tests\TestCase;

class IpAddressActionServiceTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(IpAddressActionService::class));
    }
}
