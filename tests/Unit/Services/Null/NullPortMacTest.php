<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullPortMac;
use PHPUnit\Framework\TestCase;

class NullPortMacTest extends TestCase
{
    public function test_get_forwarding_database_returns_empty_collection(): void
    {
        $provider = new NullPortMac;
        $result = $provider->getForwardingDatabase();
        $this->assertCount(0, $result);
    }
}
