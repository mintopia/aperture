<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSeatpickerCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('aperture:sync-seatpicker')
            ->assertExitCode(1);
    }

    public function test_outputs_not_configured_when_disabled(): void
    {
        $this->artisan('aperture:sync-seatpicker')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(1);
    }

    public function test_outputs_success_message_on_sync(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');

        Http::fake(['*' => Http::response([
            'data' => [],
            'meta' => ['last_page' => 1],
        ], 200)]);

        $this->artisan('aperture:sync-seatpicker')
            ->expectsOutputToContain('Synced 0 seat assignments')
            ->assertSuccessful();
    }

    public function test_returns_failure_exit_code_on_error(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');

        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $this->artisan('aperture:sync-seatpicker')
            ->expectsOutputToContain('failed')
            ->assertExitCode(1);
    }

    public function test_command_is_scheduled(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $found = $events->contains(fn ($event) => str_contains($event->command ?? '', 'aperture:sync-seatpicker'));

        $this->assertTrue($found, 'aperture:sync-seatpicker should be registered in the scheduler');
    }
}
