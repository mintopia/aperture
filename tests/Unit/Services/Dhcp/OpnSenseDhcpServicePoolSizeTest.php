<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpnSenseDhcpServicePoolSizeTest extends TestCase
{
    public function test_pool_size_is_used_from_constructor(): void
    {
        $mock = Http::sequence([
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.1', 'mac' => 'AA:BB:CC:DD:EE:01', 'hostname' => 'h1', 'status' => 'active', 'starts' => '', 'ends' => '', 'if' => 'lan'],
                    ['address' => '10.0.0.2', 'mac' => 'AA:BB:CC:DD:EE:02', 'hostname' => 'h2', 'status' => 'active', 'starts' => '', 'ends' => '', 'if' => 'lan'],
                ],
                'rowCount' => 2,
            ], 200),
        ]);

        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();
        $service = new OpnSenseDhcpService($client, 100);

        $status = $service->getPoolStatus();

        $this->assertEquals(100, $status->total);
        $this->assertEquals(2, $status->used);
        $this->assertEquals(98, $status->available);
    }
}
