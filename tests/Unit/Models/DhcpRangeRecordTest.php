<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpRangeRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpRangeRecordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_range_record(): void
    {
        $record = DhcpRangeRecord::factory()->create();

        $this->assertDatabaseHas('dhcp_range_records', [
            'id' => $record->id,
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
        ]);
    }

    public function test_can_create_ipv6_range_record(): void
    {
        $record = DhcpRangeRecord::factory()->ipv6()->create();

        $this->assertDatabaseHas('dhcp_range_records', [
            'id' => $record->id,
            'type' => 'ipv6',
        ]);
    }

    public function test_unique_constraint_prevents_duplicates(): void
    {
        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
        ]);

        $this->expectException(QueryException::class);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
        ]);
    }

    public function test_different_integrations_can_have_same_range(): void
    {
        DhcpRangeRecord::factory()->create(['integration' => 'cisco']);
        DhcpRangeRecord::factory()->create(['integration' => 'vyos']);

        $this->assertDatabaseCount('dhcp_range_records', 2);
    }
}
