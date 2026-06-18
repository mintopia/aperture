<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullCaptivePortal;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullCaptivePortalTest extends TestCase
{
    private NullCaptivePortal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullCaptivePortal;
    }

    public function test_add_ip_is_noop(): void
    {
        $this->provider->addIp('10.0.0.1', 'test');
        $this->assertTrue(true);
    }

    public function test_remove_ip_is_noop(): void
    {
        $this->provider->removeIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_add_allowed_hostnames_is_noop(): void
    {
        $this->provider->addAllowedHostnames(['example.com']);
        $this->assertTrue(true);
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_dry_run_returns_empty_result(): void
    {
        $result = $this->provider->reconcile(dryRun: true);
        $this->assertInstanceOf(ReconcileResult::class, $result);
    }
}
