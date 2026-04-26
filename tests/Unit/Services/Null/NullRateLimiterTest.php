<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullRateLimiter;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullRateLimiterTest extends TestCase
{
    private NullRateLimiter $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullRateLimiter;
    }

    public function test_limit_ip_is_noop(): void
    {
        $this->provider->limitIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_unlimit_ip_is_noop(): void
    {
        $this->provider->unlimitIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
    }
}
