<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullIpMacResolver;
use PHPUnit\Framework\TestCase;

class NullIpMacResolverTest extends TestCase
{
    public function test_get_arp_table_returns_empty_collection(): void
    {
        $provider = new NullIpMacResolver;
        $result = $provider->getArpTable();
        $this->assertCount(0, $result);
    }
}
