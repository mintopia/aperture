<?php

namespace Tests\Unit\Models;

use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Services\NtopNgService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use stdClass;
use Tests\TestCase;

class IpAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_returns_has_many_relationship(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->assertInstanceOf(HasMany::class, $ip->users());
    }

    public function test_to_string_returns_address(): void
    {
        $ip = new IpAddress;
        $ip->address = '192.168.1.1';
        $this->assertStringContainsString('192.168.1.1', (string) $ip);
        $this->assertStringContainsString('[IpAddress:', (string) $ip);
    }

    public function test_get_mac_returns_null_when_lnms_disabled(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $this->assertNull($ip->mac);
    }

    public function test_get_port_returns_null_when_lnms_disabled(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $this->assertNull($ip->port);
    }

    public function test_get_port_updated_at_returns_null_when_lnms_disabled(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $this->assertNull($ip->portUpdatedAt);
    }

    public function test_get_falls_back_to_parent_for_other_attributes(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->assertEquals('10.0.0.1', $ip->address);
    }

    public function test_shut_port_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->shutPort(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_shut_port_without_queue_returns_early_when_port_is_null(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->shutPort(false);
        $this->assertTrue(true);
    }

    public function test_unshut_port_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->unshutPort(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_unshut_port_without_queue_returns_early_when_port_is_null(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->unshutPort(false);
        $this->assertTrue(true);
    }

    public function test_limit_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->limit(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_unlimit_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->unlimit(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_allow_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->allow(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_deny_with_queue_dispatches_job(): void
    {
        Queue::fake();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->deny(true);
        Queue::assertPushed(IpAddressAction::class);
    }

    public function test_get_lnms_data_returns_empty_array_when_disabled(): void
    {
        config(['aperture.lnms.enabled' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $this->assertEquals([], $ip->getLNMSData());
    }

    public function test_get_stats_resolves_ntop_ng_service(): void
    {
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;

        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andReturn($mockStats);
        $this->app->instance(NtopNgService::class, $mock);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';

        $result = $ip->getStats();
        $this->assertSame($mockStats, $result);
    }

    public function test_update_usage_updates_received_and_sent(): void
    {
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;
        $mockStats->rsp->{'bytes.rcvd'} = 1000;
        $mockStats->rsp->{'bytes.sent'} = 2000;

        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andReturn($mockStats);
        $this->app->instance(NtopNgService::class, $mock);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->updateUsage();
        $ip->refresh();
        $this->assertEquals(1000, $ip->received);
        $this->assertEquals(2000, $ip->sent);
    }

    public function test_update_usage_handles_client_exception(): void
    {
        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andThrow(new ClientException(
                'Not found',
                new Request('GET', '/test'),
                new Response(404)
            ));
        $this->app->instance(NtopNgService::class, $mock);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $ip->updateUsage();
        $this->assertTrue(true);
    }
}
