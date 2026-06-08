<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpSyncState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DhcpSyncStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_sync_state(): void
    {
        $state = DhcpSyncState::factory()->create();

        $this->assertDatabaseHas('dhcp_sync_states', [
            'id' => $state->id,
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'dataset' => 'leases',
            'empty_count' => 0,
        ]);
    }

    public function test_unique_constraint_on_integration_family_dataset(): void
    {
        DhcpSyncState::factory()->create([
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'dataset' => 'leases',
        ]);

        $this->expectException(QueryException::class);

        DhcpSyncState::factory()->create([
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'dataset' => 'leases',
        ]);
    }

    public function test_casts_timestamps(): void
    {
        $now = Carbon::now();

        $state = DhcpSyncState::factory()->create([
            'last_attempt_at' => $now,
            'last_success_at' => $now,
        ]);

        $this->assertInstanceOf(Carbon::class, $state->last_attempt_at);
        $this->assertInstanceOf(Carbon::class, $state->last_success_at);
    }
}
