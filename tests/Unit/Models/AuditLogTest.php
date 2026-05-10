<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_record_with_non_null_subject_creates_record(): void
    {
        $ip = IpAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip.created',
            subject: $ip,
            process: 'test',
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'ip.created',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'test',
        ]);
    }

    public function test_record_with_null_subject_creates_record(): void
    {
        $log = AuditLog::record(
            action: 'settings.updated',
            process: 'admin',
            metadata: ['setting_group' => 'general'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'settings.updated',
            'subject_type' => null,
            'subject_id' => null,
            'process' => 'admin',
        ]);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertEquals(['setting_group' => 'general'], $log->metadata);
    }

    public function test_record_with_null_subject_and_actor(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::record(
            action: 'user.login_failed',
            actor: $user,
            process: 'auth',
            metadata: ['email' => 'test@example.com', 'ip' => '127.0.0.1'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'user.login_failed',
            'subject_type' => null,
            'subject_id' => null,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'process' => 'auth',
        ]);
    }
}
