<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

class KernelTest extends TestCase
{
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
}
