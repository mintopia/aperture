<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpPoolStatusRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpPoolStatusRecordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_pool_status_record(): void
    {
        $record = DhcpPoolStatusRecord::factory()->create();

        $this->assertDatabaseHas('dhcp_pool_statuses', [
            'id' => $record->id,
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'total' => '254',
            'used' => '50',
            'available' => '204',
        ]);
    }

    public function test_unique_constraint_on_integration_and_family(): void
    {
        DhcpPoolStatusRecord::factory()->create([
            'integration' => 'cisco',
            'address_family' => 'ipv4',
        ]);

        $this->expectException(QueryException::class);

        DhcpPoolStatusRecord::factory()->create([
            'integration' => 'cisco',
            'address_family' => 'ipv4',
        ]);
    }

    public function test_different_families_same_integration(): void
    {
        DhcpPoolStatusRecord::factory()->create([
            'integration' => 'cisco',
            'address_family' => 'ipv4',
        ]);
        DhcpPoolStatusRecord::factory()->ipv6()->create([
            'integration' => 'cisco',
        ]);

        $this->assertDatabaseCount('dhcp_pool_statuses', 2);
    }
}
