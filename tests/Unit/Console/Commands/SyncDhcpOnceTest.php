<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Jobs\SyncDhcpData;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Null\NullDhcpService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncDhcpOnceTest extends TestCase
{
    public function test_dhcp_sync_command_dispatches_job(): void
    {
        Queue::fake();

        $this->instance(DhcpInterface::class, new NullDhcpService);

        $this->artisan('dhcp:sync')
            ->assertExitCode(0);

        Queue::assertPushed(SyncDhcpData::class);
    }

    public function test_dhcp_sync_command_outputs_success_message(): void
    {
        Queue::fake();

        $this->instance(DhcpInterface::class, new NullDhcpService);

        $this->artisan('dhcp:sync')
            ->expectsOutputToContain('DHCP sync completed.')
            ->assertSuccessful();
    }

    public function test_dhcp_sync_job_is_scheduled(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());

        $found = $events->first(fn ($event): bool => str_contains($event->description ?? '', 'SyncDhcpData'));

        $this->assertNotNull($found, 'SyncDhcpData should be scheduled');
        $this->assertSame('* * * * *', $found->expression);
        $this->assertTrue($found->onOneServer, 'SyncDhcpData should use onOneServer');
        $this->assertTrue($found->withoutOverlapping, 'SyncDhcpData should use withoutOverlapping');
    }
}
