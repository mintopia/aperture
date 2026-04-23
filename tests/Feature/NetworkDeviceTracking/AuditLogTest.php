<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_audit_log(): void
    {
        $ip = IpAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip.created',
            subject: $ip,
            process: 'scan_network',
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'ip.created',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ]);
    }

    public function test_record_with_related_entity(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip_mac.linked',
            subject: $ip,
            related: $mac,
            process: 'scan_network',
        );

        $this->assertEquals($mac->getMorphClass(), $log->related_type);
        $this->assertEquals($mac->id, $log->related_id);
    }

    public function test_record_with_actor(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $log = AuditLog::record(
            action: 'ip.internet_enabled',
            subject: $ip,
            actor: $user,
            process: 'admin',
        );

        $this->assertEquals($user->getMorphClass(), $log->actor_type);
        $this->assertEquals($user->id, $log->actor_id);
    }

    public function test_record_with_metadata(): void
    {
        $ip = IpAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip.created',
            subject: $ip,
            process: 'scan_network',
            metadata: ['source' => 'dhcp'],
        );

        $this->assertEquals(['source' => 'dhcp'], $log->metadata);
    }

    public function test_subject_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $log = AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->subject->is($ip));
    }

    public function test_related_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $log = AuditLog::record(action: 'ip_mac.linked', subject: $ip, related: $mac, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->related->is($mac));
    }

    public function test_actor_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $log = AuditLog::record(action: 'ip.created', subject: $ip, actor: $user, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->actor->is($user));
    }

    public function test_audit_log_has_no_updated_at(): void
    {
        $ip = IpAddress::factory()->create();
        $log = AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $this->assertNull($log->updated_at);
    }
}
