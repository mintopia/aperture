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

    public function test_is_enabled_for_ip_returns_false(): void
    {
        $this->assertFalse($this->provider->isEnabledForIp('10.0.0.1'));
    }

    public function test_enable_for_ip_is_noop(): void
    {
        $this->provider->enableForIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_disable_for_ip_is_noop(): void
    {
        $this->provider->disableForIp('10.0.0.1');
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
