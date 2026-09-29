<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LibreNms;

use App\Services\LibreNms\LibreNmsPortMac;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\ForwardingEntry;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class LibreNmsPortMacTest extends TestCase
{
    private LibreNmsService&MockInterface $libreNms;

    private LibreNmsPortMac $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libreNms = Mockery::mock(LibreNmsService::class);
        $this->service = new LibreNmsPortMac(
            libreNms: $this->libreNms,
        );
    }

    public function test_get_forwarding_database_delegates_to_libre_nms_service(): void
    {
        $entries = collect([
            new ForwardingEntry(mac: 'aa:bb:cc:dd:ee:01', port: '101', vlan: 10),
            new ForwardingEntry(mac: 'aa:bb:cc:dd:ee:02', port: '102', vlan: 20),
        ]);

        $this->libreNms->shouldReceive('getForwardingDatabase')
            ->once()
            ->andReturn($entries);

        $result = $this->service->getForwardingDatabase();

        $this->assertCount(2, $result);
        $this->assertSame('aa:bb:cc:dd:ee:01', $result->get(0)->mac);
        $this->assertSame('101', $result->get(0)->port);
        $this->assertSame(10, $result->get(0)->vlan);
        $this->assertSame('aa:bb:cc:dd:ee:02', $result->get(1)->mac);
    }

    public function test_get_forwarding_database_returns_empty_collection_when_no_data(): void
    {
        $this->libreNms->shouldReceive('getForwardingDatabase')
            ->once()
            ->andReturn(collect());

        $result = $this->service->getForwardingDatabase();

        $this->assertCount(0, $result);
    }
}
