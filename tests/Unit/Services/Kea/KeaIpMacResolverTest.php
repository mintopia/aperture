<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaIpMacResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class KeaIpMacResolverTest extends TestCase
{
    public function test_implements_ip_mac_resolver_interface(): void
    {
        $this->assertInstanceOf(IpMacResolverInterface::class, new KeaIpMacResolver);
    }

    public function test_get_arp_table_returns_empty_collection(): void
    {
        $resolver = new KeaIpMacResolver;
        $table = $resolver->getArpTable();

        $this->assertInstanceOf(Collection::class, $table);
        $this->assertTrue($table->isEmpty());
    }
}
