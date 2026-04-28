<?php

namespace Tests\Unit\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_add_allowed_hostnames_fetches_settings_and_updates_zone(): void
    {
        $settingsResponse = (object) [
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [
                                '1.1.1.1' => (object) ['value' => '1.1.1.1'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/settings/get')
            ->willReturn($settingsResponse);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/settings/setZone/uuid-1',
                [],
                $this->callback(function (array $payload): bool {
                    $this->assertArrayHasKey('zone', $payload);
                    $this->assertArrayHasKey('allowedAddresses', $payload['zone']);
                    // The existing 1.1.1.1 should be preserved, plus 127.0.0.1 from localhost
                    $addresses = explode(',', $payload['zone']['allowedAddresses']);
                    $this->assertContains('1.1.1.1', $addresses);
                    $this->assertContains('127.0.0.1', $addresses);

                    return true;
                }),
            )
            ->willReturn((object) ['result' => 'saved']);

        $this->portal->addAllowedHostnames(['localhost']);
    }

    public function test_add_allowed_hostnames_throws_on_malformed_response(): void
    {
        $settingsResponse = (object) [
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => 'not-an-object',
                ],
            ],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($settingsResponse);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Response is malformed');
        $this->portal->addAllowedHostnames(['example.com']);
    }

    public function test_add_allowed_hostnames_skips_non_matching_zone(): void
    {
        $settingsResponse = (object) [
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 99,
                        ],
                        'uuid-2' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($settingsResponse);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/settings/setZone/uuid-2',
                [],
                $this->anything(),
            )
            ->willReturn((object) ['result' => 'saved']);

        $this->portal->addAllowedHostnames(['localhost']);
    }

    public function test_add_allowed_hostnames_skips_non_object_zones(): void
    {
        $settingsResponse = (object) [
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => 'not-an-object',
                        'uuid-2' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($settingsResponse);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/api/captiveportal/settings/setZone/uuid-2',
                [],
                $this->anything(),
            )
            ->willReturn((object) ['result' => 'saved']);

        $this->portal->addAllowedHostnames(['localhost']);
    }

    public function test_add_allowed_hostnames_skips_unresolvable_hostnames(): void
    {
        $settingsResponse = (object) [
            'zone' => (object) [
                'zones' => (object) [
                    'zone' => (object) [
                        'uuid-1' => (object) [
                            'zoneid' => 1,
                            'allowedAddresses' => (object) [],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($settingsResponse);

        $this->client->expects($this->once())
            ->method('post')
            ->willReturn((object) ['result' => 'saved']);

        $this->portal->addAllowedHostnames(['this-hostname-definitely-does-not-exist-xyz123.invalid']);
    }

    public function test_reconcile_adds_desired_ips_not_currently_connected(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'internet_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.3', 'internet_enabled' => false]);

        // fetchConnectedIps returns only 10.0.0.1
        $sessionList = (object) [
            0 => (object) ['ipAddress' => '10.0.0.1'],
        ];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        // addIp is called for 10.0.0.2 (the one not already connected)
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

        // 10.0.0.1 and 10.0.0.99 are connected, but 10.0.0.99 is not desired
        $sessionList = (object) [
            0 => (object) ['sessionId' => 'sess-1', 'ipAddress' => '10.0.0.1'],
            1 => (object) ['sessionId' => 'sess-99', 'ipAddress' => '10.0.0.99'],
        ];

        // GET is called twice: once by fetchConnectedIps in reconcile, once by removeIp
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

        // No IPs currently connected
        $sessionList = (object) [];

        $this->client->expects($this->once())
            ->method('get')
            ->with('/api/captiveportal/session/list', ['zoneid' => 1])
            ->willReturn($sessionList);

        // No post calls should be made in dry run
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
        // No IPs in DB, no sessions connected
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
        // Create a mix of desired IPs: 50 that should be unchanged, 50 that should be added
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

        // Current IPs include the 50 unchanged + 30 that should be removed
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
        // Two sessions with the same IP
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
}
