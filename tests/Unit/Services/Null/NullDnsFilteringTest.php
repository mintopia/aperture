<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullDnsFiltering;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullDnsFilteringTest extends TestCase
{
    private NullDnsFiltering $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullDnsFiltering;
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
    }
}
