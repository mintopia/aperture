<?php

namespace Tests\Unit\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use Tests\TestCase;

class OpnSenseCaptivePortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private OpnSenseClient&MockObject $client;

    private OpnSenseCaptivePortal $portal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(OpnSenseClient::class);
        $this->portal = new OpnSenseCaptivePortal($this->client, zoneId: 1);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_implements_captive_portal_interface(): void
    {
        $this->assertInstanceOf(CaptivePortalInterface::class, $this->portal);
    }

    public function test_add_ip_posts_to_captive_portal_session_connect(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/connect',
                ['zoneid' => 1],
                (object) ['user' => 'Test User', 'ip' => '10.0.0.1'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->addIp('10.0.0.1', 'Test User');
    }

    public function test_add_ip_propagates_backend_exception(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->willThrowException(new BackendException('Connection refused'));

        $this->expectException(BackendException::class);
        $this->portal->addIp('10.0.0.1', 'Test User');
    }

    public function test_remove_ip_finds_session_and_disconnects(): void
    {
        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
            1 => (object) ['sessionId' => 'sess-2', 'ipAddress' => '10.0.0.2'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/disconnect',
                ['zoneid' => 1],
                ['sessionId' => 'sess-1'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->removeIp('10.0.0.1');
    }

    public function test_remove_ip_handles_no_matching_session(): void
    {
        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.99'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->never())
            ->method('post');

        $this->portal->removeIp('10.0.0.1');
    }

    public function test_remove_ip_skips_non_object_sessions(): void
    {
        $sessionList = (object) [
            0 => 'some-string-entry',
            1 => 42,
            2 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/disconnect',
                ['zoneid' => 1],
                ['sessionId' => 'sess-1'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->removeIp('10.0.0.1');
    }

    public function test_reconcile_adds_desired_ips_not_currently_connected(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.3', 'internet_enabled' => false]);

        $sessionList = (object) [
            0 => (object) ['ipAddress' => '10.0.0.1'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/connect',
                ['zoneid' => 1],
                (object) ['user' => 'Reconciled', 'ip' => '10.0.0.2'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $result = $this->portal->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.2'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_removes_connected_ips_not_in_desired(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);

        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
            1 => (object) ['sessionId' => 'sess-99', 'ipAddress' => '10.0.0.99'],
        ];

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/disconnect',
                ['zoneid' => 1],
                ['sessionId' => 'sess-99'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $result = $this->portal->reconcile();

        $this->assertSame([], $result->added);
        $this->assertContains('10.0.0.99', $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
    }

    public function test_reconcile_dry_run_skips_actions(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);

        $sessionList = (object) [];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->portal->reconcile(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_captures_errors_per_ip(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => true]);

        $sessionList = (object) [];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->exactly(2))
            ->method('post')
            ->willThrowException(new BackendException('Connection refused'));

        $result = $this->portal->reconcile();

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->added);
        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('10.0.0.1: Connection refused', $result->errors[0]);
        $this->assertStringContainsString('10.0.0.2: Connection refused', $result->errors[1]);
    }

    public function test_reconcile_handles_empty_state(): void
    {
        $sessionList = (object) [];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->portal->reconcile();

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_correctly_categorises_large_ip_sets(): void
    {
        $unchangedIps = [];
        $addedIps = [];
        for ($i = 1; $i <= 50; $i++) {
            $ip = '10.1.0.'.$i;
            $unchangedIps[] = $ip;
            IpAddress::factory()->create(['address' => $ip, 'internet_enabled' => true]);
        }

        for ($i = 51; $i <= 100; $i++) {
            $ip = '10.1.0.'.$i;
            $addedIps[] = $ip;
            IpAddress::factory()->create(['address' => $ip, 'internet_enabled' => true]);
        }

        $removedIps = [];
        $currentSessionIps = $unchangedIps;
        for ($i = 101; $i <= 130; $i++) {
            $ip = '10.1.0.'.$i;
            $removedIps[] = $ip;
            $currentSessionIps[] = $ip;
        }

        $sessionList = new stdClass;
        foreach ($currentSessionIps as $idx => $ip) {
            $sessionList->{$idx} = (object) ['sessionId' => 'sess-'.$idx, 'ipAddress' => $ip];
        }

        $this->client->expects($this->atLeastOnce())
            ->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->atLeastOnce())
            ->method('post')
            ->willReturn((object) ['status' => 'ok']);

        $result = $this->portal->reconcile();

        $resultAdded = $result->added;
        $resultRemoved = $result->removed;
        $resultUnchanged = $result->unchanged;
        sort($resultAdded);
        sort($resultRemoved);
        sort($resultUnchanged);
        sort($addedIps);
        sort($removedIps);
        sort($unchangedIps);

        $this->assertSame($addedIps, $resultAdded);
        $this->assertSame($removedIps, $resultRemoved);
        $this->assertSame($unchangedIps, $resultUnchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_fetch_connected_ips_deduplicates(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.99', 'internet_enabled' => true]);

        $sessionList = (object) [
            0 => (object) ['ipAddress' => '10.0.0.99'],
            1 => (object) ['ipAddress' => '10.0.0.99'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->portal->reconcile();

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['10.0.0.99'], $result->unchanged);
    }

    public function test_add_ip_sends_uppercase_ipv6_lowercased(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/connect',
                ['zoneid' => 1],
                (object) ['user' => 'Test User', 'ip' => '2001:db8::abcd'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->addIp('2001:DB8::ABCD', 'Test User');
    }

    public function test_add_ip_passes_ipv4_through_byte_identical(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/connect',
                ['zoneid' => 1],
                (object) ['user' => 'Test User', 'ip' => '192.0.2.10'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->addIp('192.0.2.10', 'Test User');
    }

    public function test_remove_ip_uppercase_input_disconnects_lowercase_session(): void
    {
        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-v6', 'ipAddress' => '2001:db8::abcd'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/disconnect',
                ['zoneid' => 1],
                ['sessionId' => 'sess-v6'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->removeIp('2001:DB8::ABCD');
    }

    public function test_remove_ip_lowercase_input_disconnects_uppercase_session(): void
    {
        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-v6', 'ipAddress' => '2001:DB8::ABCD'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/session/disconnect',
                ['zoneid' => 1],
                ['sessionId' => 'sess-v6'],
            )
            ->willReturn((object) ['status' => 'ok']);

        $this->portal->removeIp('2001:db8::abcd');
    }

    public function test_reconcile_treats_case_mismatched_ipv6_as_unchanged(): void
    {
        IpAddress::factory()->create(['address' => '2001:db8::1', 'internet_enabled' => true]);

        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '2001:DB8::1'],
        ];

        $this->client->method('get')
            ->willReturn($sessionList);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->portal->reconcile();

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['2001:db8::1'], $result->unchanged);
        $this->assertSame([], $result->errors);
    }
}
