<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\AuditLogRecorded;
use App\Models\AuditLog;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuditLogRecordedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_record_dispatches_broadcast_event(): void
    {
        Event::fake([AuditLogRecorded::class]);

        $log = AuditLog::record(action: 'user.login', severity: 'info');

        Event::assertDispatched(AuditLogRecorded::class, fn (AuditLogRecorded $e): bool => $e->log->is($log));
    }

    public function test_event_is_broadcastable_with_expected_payload(): void
    {
        $log = AuditLog::record(action: 'dhcp.threshold_reached', metadata: ['pool' => 'lan'], severity: 'warning');

        $event = new AuditLogRecorded($log);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame($log->id, $payload['id']);
        $this->assertSame('dhcp.threshold_reached', $payload['action']);
        $this->assertSame('warning', $payload['severity']);
        $this->assertArrayHasKey('description', $payload);
        $this->assertArrayHasKey('created_at', $payload);
    }
}
