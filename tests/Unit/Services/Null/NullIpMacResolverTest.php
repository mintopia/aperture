<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullIpMacResolver;
use PHPUnit\Framework\TestCase;

class NullIpMacResolverTest extends TestCase
{
    public function test_get_ip_mac_table_returns_empty_collection(): void
    {
        $provider = new NullIpMacResolver;
        $result = $provider->getIpMacTable();
        $this->assertCount(0, $result);
    }
}
