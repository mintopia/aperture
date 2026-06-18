<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class ReconcileResultTest extends TestCase
{
    public function test_can_be_constructed_with_arrays(): void
    {
        $result = new ReconcileResult(
            added: ['10.0.0.1', '10.0.0.2'],
            removed: ['10.0.0.3'],
            unchanged: ['10.0.0.4'],
            errors: ['10.0.0.5: connection refused'],
        );

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->added);
        $this->assertSame(['10.0.0.3'], $result->removed);
        $this->assertSame(['10.0.0.4'], $result->unchanged);
        $this->assertSame(['10.0.0.5: connection refused'], $result->errors);
    }

    public function test_can_be_constructed_with_empty_arrays(): void
    {
        $result = new ReconcileResult(
            added: [],
            removed: [],
            unchanged: [],
            errors: [],
        );

        $this->assertEmpty($result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }
}
