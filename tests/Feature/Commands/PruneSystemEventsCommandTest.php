<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\SystemEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PruneSystemEventsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deletes_events_older_than_retention_period(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->create(['created_at' => now()->subDays(31)]);
        SystemEvent::factory()->create(['created_at' => now()->subDays(35)]);
        $recent = SystemEvent::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 1);
        $this->assertDatabaseHas('system_events', ['id' => $recent->id]);
    }

    public function test_respects_custom_retention_config(): void
    {
        config(['events.retention_days' => 7]);

        SystemEvent::factory()->create(['created_at' => now()->subDays(8)]);
        $recent = SystemEvent::factory()->create(['created_at' => now()->subDays(3)]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 1);
        $this->assertDatabaseHas('system_events', ['id' => $recent->id]);
    }

    public function test_does_nothing_when_no_old_events(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->count(3)->create(['created_at' => now()]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 3);
    }

    public function test_outputs_deleted_count(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->count(5)->create(['created_at' => now()->subDays(31)]);

        $this->artisan('events:prune')
            ->expectsOutputToContain('5')
            ->assertSuccessful();
    }
}
