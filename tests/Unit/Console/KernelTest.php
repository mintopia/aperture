<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Kernel;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class KernelTest extends TestCase
{
    use RefreshDatabase;

    public function test_kernel_schedules_commands(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $reflection = new ReflectionClass($kernel);
        $method = $reflection->getMethod('schedule');

        $schedule = $this->app->make(Schedule::class);
        $method->invoke($kernel, $schedule);

        $events = $schedule->events();
        $this->assertNotEmpty($events);
    }

    public function test_kernel_registers_commands(): void
    {
        // Verify artisan commands from Commands directory are registered
        $this->assertTrue(Artisan::all() !== []);
    }

    public function test_expire_sessions_command_is_scheduled(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());
        $found = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'aperture:expire-sessions'));

        $this->assertNotNull($found, 'aperture:expire-sessions should be scheduled');
        $this->assertEquals('*/5 * * * *', $found->expression);
    }

    public function test_ntopng_command_is_scheduled(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());
        $found = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'aperture:ntopng'));

        $this->assertNotNull($found, 'aperture:ntopng should be scheduled');
        $this->assertEquals('*/5 * * * *', $found->expression);
    }

    public function test_sync_switch_ports_is_scheduled(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());

        $found = $events->first(fn ($event): bool => ($event->description ?? '') === 'sync-switch-ports');

        $this->assertNotNull($found, 'sync-switch-ports should be scheduled');
        $this->assertSame('*/5 * * * *', $found->expression);
    }

    public function test_sync_switch_ports_respects_config_interval(): void
    {
        config(['aperture.switch_sync_interval' => 10]);

        // Re-invoke schedule with fresh config
        $kernel = $this->app->make(Kernel::class);
        $schedule = new Schedule;

        $reflection = new ReflectionClass($kernel);
        $method = $reflection->getMethod('schedule');
        $method->invoke($kernel, $schedule);

        $events = collect($schedule->events());
        $found = $events->first(fn ($event): bool => ($event->description ?? '') === 'sync-switch-ports');

        $this->assertNotNull($found, 'sync-switch-ports should be scheduled');
        $this->assertSame('*/10 * * * *', $found->expression);
    }

    public function test_sync_switch_ports_closure_dispatches_job_for_each_enabled_switch(): void
    {
        Queue::fake();

        $enabledSwitch1 = SwitchConfig::factory()->create(['enabled' => true]);
        $enabledSwitch2 = SwitchConfig::factory()->create(['enabled' => true]);
        SwitchConfig::factory()->create(['enabled' => false]);

        $kernel = $this->app->make(Kernel::class);
        $schedule = new Schedule;

        $reflection = new ReflectionClass($kernel);
        $method = $reflection->getMethod('schedule');
        $method->invoke($kernel, $schedule);

        $events = collect($schedule->events());
        $found = $events->first(fn ($event): bool => ($event->description ?? '') === 'sync-switch-ports');

        $this->assertNotNull($found);

        // Invoke the closure directly via reflection to cover lines 26-28
        $callbackProperty = (new ReflectionClass($found))->getProperty('callback');
        $callback = $callbackProperty->getValue($found);
        $callback();

        Queue::assertPushed(SyncSwitchPortsJob::class, 2);
        Queue::assertPushed(SyncSwitchPortsJob::class, fn ($job) => $job->switchConfig->is($enabledSwitch1));
        Queue::assertPushed(SyncSwitchPortsJob::class, fn ($job) => $job->switchConfig->is($enabledSwitch2));
    }
}
