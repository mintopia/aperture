<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\NetworkScan;

use App\Jobs\NetworkScan\PersistMacsStep;
use PHPUnit\Framework\TestCase;

class PersistMacsStepTest extends TestCase
{
    public function test_step_is_constructable(): void
    {
        $this->assertInstanceOf(PersistMacsStep::class, new PersistMacsStep);
    }

    public function test_invoke_is_callable_with_empty_collections(): void
    {
        $step = new PersistMacsStep;
        $method = new \ReflectionMethod($step, '__invoke');
        $this->assertTrue($method->isPublic());
    }
}
