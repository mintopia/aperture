<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_address_mac_address_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('ip_address_mac_address'));
        $this->assertTrue(Schema::hasColumns('ip_address_mac_address', [
            'id', 'ip_address_id', 'mac_address_id', 'source', 'last_seen_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_dhcp_leases_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('dhcp_leases'));
        $this->assertTrue(Schema::hasColumns('dhcp_leases', [
            'id', 'ip_address_id', 'mac_address_id', 'hostname', 'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_audit_logs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id', 'action', 'subject_type', 'subject_id', 'related_type', 'related_id',
            'actor_type', 'actor_id', 'process', 'metadata', 'created_at',
        ]));
    }

    public function test_switch_port_macs_has_mac_address_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('switch_port_macs', 'mac_address_id'));
    }

    public function test_ip_addresses_no_longer_has_mac_address_id(): void
    {
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'mac_address_id'));
    }

    public function test_ip_addresses_no_longer_has_user_id(): void
    {
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'user_id'));
    }

    public function test_mac_addresses_no_longer_has_allowed_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('mac_addresses', 'allowed'));
        $this->assertFalse(Schema::hasColumn('mac_addresses', 'allowed_at'));
    }
}
