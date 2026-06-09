<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AuditLog;

use App\Models\AuditLog;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPortMac;
use App\Models\User;
use App\Services\AuditLog\AuditLogDescriptionGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditLogDescriptionGeneratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function makeLog(
        string $action,
        ?Model $subject = null,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null,
    ): AuditLog {
        $log = AuditLog::record(
            action: $action,
            subject: $subject,
            related: $related,
            actor: $actor,
            process: $process,
            metadata: $metadata,
        );

        return $log->load(['subject', 'related', 'actor']);
    }

    public function test_user_login(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.login', subject: $user, process: 'auth');

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertSame('Alice logged in', $description);
        $this->assertStringContainsString('logged in', $description);
    }

    public function test_user_login_failed_uses_metadata_email(): void
    {
        $log = $this->makeLog('user.login_failed', process: 'auth', metadata: ['email' => 'bob@example.com']);

        $this->assertSame(
            'Failed login attempt for bob@example.com',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_login_failed_without_email_metadata(): void
    {
        $log = $this->makeLog('user.login_failed', process: 'auth');

        $this->assertSame(
            'Failed login attempt for an unknown user',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_logout(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.logout', subject: $user, process: 'auth');

        $this->assertSame('Alice logged out', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_captive_login(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.captive_login', subject: $user, process: 'captive');

        $this->assertSame(
            'Alice logged in via the captive portal',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_password_created(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.password_created', subject: $user, process: 'account');

        $this->assertSame('Alice set a password', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_password_changed(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.password_changed', subject: $user, process: 'account');

        $this->assertSame('Alice changed their password', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_password_cleared(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.password_cleared', subject: $user, process: 'account');

        $this->assertSame('Alice cleared their password', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_passkey_registered(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.passkey_registered', subject: $user, process: 'account');

        $this->assertSame('Alice registered a passkey', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_passkey_deleted(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.passkey_deleted', subject: $user, process: 'account');

        $this->assertSame('Alice deleted a passkey', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_role_changed(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.role_changed', subject: $user, process: 'admin', metadata: [
            'roles' => ['admin', 'user'],
        ]);

        $this->assertSame(
            'Changed roles for Alice to admin, user',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_role_changed_with_empty_roles(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.role_changed', subject: $user, process: 'admin', metadata: [
            'roles' => [],
        ]);

        $this->assertSame(
            'Changed roles for Alice to none',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_role_changed_with_non_scalar_role_entry(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.role_changed', subject: $user, process: 'admin', metadata: [
            'roles' => [['nested']],
        ]);

        $this->assertSame(
            'Changed roles for Alice to ?',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_block_toggled_blocked(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.block_toggled', subject: $user, process: 'admin', metadata: ['blocked' => true]);

        $this->assertSame('Blocked internet for Alice', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_block_toggled_unblocked(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.block_toggled', subject: $user, process: 'admin', metadata: ['blocked' => false]);

        $this->assertSame('Unblocked internet for Alice', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_internet_toggled_enabled(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.internet_toggled', subject: $user, process: 'admin', metadata: ['enabled' => true]);

        $this->assertSame('Enabled internet for Alice', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_internet_toggled_disabled(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.internet_toggled', subject: $user, process: 'admin', metadata: ['enabled' => false]);

        $this->assertSame('Disabled internet for Alice', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_user_dns_filter_toggled(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('user.dns_filter_toggled', subject: $user, process: 'portal', metadata: ['enabled' => true]);

        $this->assertSame('Enabled DNS filtering for Alice', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_mac_user_assigned(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('mac.user_assigned', subject: $mac, related: $ip, actor: $user, process: 'portal');

        $this->assertSame(
            'Assigned AA:BB:CC:DD:EE:FF to Alice',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_mac_created(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $log = $this->makeLog('mac.created', subject: $mac, process: 'scan_network', metadata: ['source' => 'arp']);

        $this->assertSame(
            'Discovered new device AA:BB:CC:DD:EE:FF via arp',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_ip_user_cascaded(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.user_cascaded', subject: $ip, related: $mac, actor: $user, process: 'portal');

        $this->assertSame(
            'Assigned 10.30.0.1 to Alice via AA:BB:CC:DD:EE:FF',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_ip_internet_toggled(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.internet_toggled', subject: $ip, process: 'admin', metadata: ['enabled' => true]);

        $this->assertSame('Enabled internet for 10.30.0.1', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_ip_rate_limit_toggled(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.rate_limit_toggled', subject: $ip, process: 'admin', metadata: ['enabled' => false]);

        $this->assertSame('Disabled rate limiting for 10.30.0.1', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_ip_dns_filter_toggled(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.dns_filter_toggled', subject: $ip, process: 'admin', metadata: ['enabled' => true]);

        $this->assertSame('Enabled DNS filtering for 10.30.0.1', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_ip_session_expired(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.session_expired', subject: $ip, metadata: ['reason' => 'session_timeout']);

        $this->assertSame(
            'Session expired for 10.30.0.1, internet disabled',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_ip_created(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.created', subject: $ip, process: 'scan_network', metadata: ['source' => 'dhcp']);

        $this->assertSame(
            'Discovered new IP 10.30.0.1 via dhcp',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_ip_mac_linked(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip_mac.linked', subject: $ip, related: $mac, process: 'dhcp');

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertSame('Linked AA:BB:CC:DD:EE:FF to 10.30.0.1 via dhcp', $description);
        $this->assertStringContainsString('AA:BB:CC:DD:EE:FF', $description);
        $this->assertStringContainsString('10.30.0.1', $description);
    }

    public function test_port_mac_linked(): void
    {
        $spm = SwitchPortMac::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $log = $this->makeLog('port_mac.linked', subject: $spm, process: 'scan_network', metadata: ['mac' => 'aa:bb:cc:dd:ee:ff']);

        $this->assertSame(
            'Linked aa:bb:cc:dd:ee:ff to a switch port',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_port_mac_linked_without_metadata_uses_subject_label(): void
    {
        $spm = SwitchPortMac::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $log = $this->makeLog('port_mac.linked', subject: $spm, process: 'scan_network');

        $this->assertSame(
            'Linked SwitchPortMac #'.$spm->id.' to a switch port',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_oui_auto_allowed(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('oui.auto_allowed', subject: $ip, related: $mac, process: 'oui_policy', metadata: ['mac' => 'aa:bb:cc:dd:ee:ff']);

        $this->assertSame(
            'Automatically enabled internet for 10.30.0.1 (AA:BB:CC:DD:EE:FF) by OUI policy',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_switch_created(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'Core Switch']);
        $log = $this->makeLog('switch.created', subject: $switch, process: 'admin');

        $this->assertSame('Created switch Core Switch', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_switch_updated(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'Core Switch']);
        $log = $this->makeLog('switch.updated', subject: $switch, process: 'admin');

        $this->assertSame('Updated switch Core Switch', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_switch_deleted(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'Core Switch']);
        $log = $this->makeLog('switch.deleted', subject: $switch, process: 'admin');

        $this->assertSame('Deleted switch Core Switch', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_settings_updated(): void
    {
        $log = $this->makeLog('settings.updated', process: 'admin', metadata: ['setting_group' => 'general']);

        $this->assertSame('Updated general settings', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_settings_updated_without_group(): void
    {
        $log = $this->makeLog('settings.updated', process: 'admin');

        $this->assertSame('Updated application settings', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_integration_updated(): void
    {
        $config = IntegrationConfig::factory()->create();
        $log = $this->makeLog('integration.updated', subject: $config, process: 'admin', metadata: ['service' => 'cisco']);

        $this->assertSame('Updated cisco integration settings', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_portal_reset(): void
    {
        $user = User::factory()->create(['nickname' => 'Alice']);
        $log = $this->makeLog('portal.reset', subject: $user, actor: $user, process: 'admin');

        $this->assertSame('Alice reset the portal', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_portal_reset_without_actor_uses_unknown_label(): void
    {
        $log = $this->makeLog('portal.reset', process: 'admin');

        $this->assertSame('unknown reset the portal', AuditLogDescriptionGenerator::generate($log));
    }

    public function test_unknown_action_fallback(): void
    {
        $log = $this->makeLog('custom.thing_happened');

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertSame('Custom thing happened', $description);
        $this->assertStringContainsString('thing happened', $description);
    }

    public function test_fallback_appends_subject_label(): void
    {
        $config = IntegrationConfig::factory()->create();
        $log = $this->makeLog('integration.deleted', subject: $config, process: 'admin');

        $this->assertSame(
            'Integration deleted IntegrationConfig #'.$config->id,
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_fallback_appends_related_label(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip_mac.unlinked', subject: $ip, related: $mac, process: 'scan_network');

        $this->assertSame(
            'Ip mac unlinked 10.30.0.1 → AA:BB:CC:DD:EE:FF',
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_label_for_deleted_subject_uses_type_and_id(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        $log = $this->makeLog('ip.purged', subject: $ip, process: 'system');
        $ip->delete();
        $log = $log->fresh(['subject', 'related', 'actor']) ?? $log;

        $this->assertSame(
            'Ip purged IpAddress #'.$log->subject_id,
            AuditLogDescriptionGenerator::generate($log),
        );
    }

    public function test_user_label_falls_back_to_email_when_nickname_empty(): void
    {
        $user = User::factory()->create(['nickname' => '', 'email' => 'alice@example.com']);
        $log = $this->makeLog('user.login', subject: $user, process: 'auth');

        $this->assertSame('alice@example.com logged in', AuditLogDescriptionGenerator::generate($log));
    }
}
