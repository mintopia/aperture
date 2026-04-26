<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\BandwidthAnomalyDetected;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use App\Services\ValueObjects\TopTalker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DetectBandwidthAnomaliesCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_fires_event_when_anomaly_detected(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $user = User::factory()->create(['nickname' => 'TestUser']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.50']);
        $user->addIp($ip->address);

        $shortTermResult = new IpBandwidthResult(
            received: 150000,
            sent: 0,
            timestamps: [],
            download: [150000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 30000,
            sent: 0,
            timestamps: [],
            download: [30000.0],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.50', received: 150000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        config(['aperture.bandwidth_anomaly.threshold' => 3.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertDispatched(BandwidthAnomalyDetected::class, function (BandwidthAnomalyDetected $event): bool {
            return $event->ipAddress === '10.0.0.50'
                && $event->userName === 'TestUser'
                && $event->shortTermAvg === 150000.0
                && $event->longTermAvg === 30000.0
                && $event->ratio === 5.0
                && $event->threshold === 3.0;
        });
    }

    public function test_command_does_not_fire_event_when_no_anomaly(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $shortTermResult = new IpBandwidthResult(
            received: 30000,
            sent: 0,
            timestamps: [],
            download: [30000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 30000,
            sent: 0,
            timestamps: [],
            download: [30000.0],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.50', received: 30000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        config(['aperture.bandwidth_anomaly.threshold' => 3.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertNotDispatched(BandwidthAnomalyDetected::class);
    }

    public function test_command_handles_empty_top_talkers(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect());
        $this->app->instance(IpBandwidthInterface::class, $mock);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertNotDispatched(BandwidthAnomalyDetected::class);
    }

    public function test_command_handles_prometheus_exception_gracefully(): void
    {
        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(IpBandwidthInterface::class, $mock);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();
    }

    public function test_command_uses_configured_threshold(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $shortTermResult = new IpBandwidthResult(
            received: 120000,
            sent: 0,
            timestamps: [],
            download: [120000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 30000,
            sent: 0,
            timestamps: [],
            download: [30000.0],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.50', received: 120000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        // Ratio is 4.0 but threshold is 5.0 so should not fire
        config(['aperture.bandwidth_anomaly.threshold' => 5.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertNotDispatched(BandwidthAnomalyDetected::class);
    }

    public function test_command_resolves_user_for_ip(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $user = User::factory()->create(['nickname' => 'JaneDoe']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.100']);
        $user->addIp($ip->address);

        $shortTermResult = new IpBandwidthResult(
            received: 300000,
            sent: 0,
            timestamps: [],
            download: [300000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 50000,
            sent: 0,
            timestamps: [],
            download: [50000.0],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.100', received: 300000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.100', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.100', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        config(['aperture.bandwidth_anomaly.threshold' => 3.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertDispatched(BandwidthAnomalyDetected::class, function (BandwidthAnomalyDetected $event): bool {
            return $event->ipAddress === '10.0.0.100'
                && $event->userName === 'JaneDoe'
                && $event->userId !== null;
        });
    }

    public function test_command_fires_event_with_null_user_when_ip_has_no_user(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $shortTermResult = new IpBandwidthResult(
            received: 300000,
            sent: 0,
            timestamps: [],
            download: [300000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 50000,
            sent: 0,
            timestamps: [],
            download: [50000.0],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.200', received: 300000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.200', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.200', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        config(['aperture.bandwidth_anomaly.threshold' => 3.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertDispatched(BandwidthAnomalyDetected::class, function (BandwidthAnomalyDetected $event): bool {
            return $event->ipAddress === '10.0.0.200'
                && $event->userName === null
                && $event->userId === null;
        });
    }

    public function test_command_handles_zero_long_term_average(): void
    {
        Event::fake([BandwidthAnomalyDetected::class]);

        $shortTermResult = new IpBandwidthResult(
            received: 120000,
            sent: 0,
            timestamps: [],
            download: [120000.0],
            upload: [],
        );
        $longTermResult = new IpBandwidthResult(
            received: 0,
            sent: 0,
            timestamps: [],
            download: [],
            upload: [],
        );

        /** @var IpBandwidthInterface&MockInterface $mock */
        $mock = Mockery::mock(IpBandwidthInterface::class);
        $mock->shouldReceive('getTopTalkers')
            ->once()
            ->andReturn(collect([new TopTalker(ip: '10.0.0.50', received: 120000, sent: 0)]));
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '5m')
            ->once()
            ->andReturn($shortTermResult);
        $mock->shouldReceive('getIpBandwidth')
            ->with('10.0.0.50', '1h')
            ->once()
            ->andReturn($longTermResult);
        $this->app->instance(IpBandwidthInterface::class, $mock);

        config(['aperture.bandwidth_anomaly.threshold' => 3.0]);

        $this->artisan('aperture:detect-bandwidth-anomalies')
            ->assertSuccessful();

        Event::assertNotDispatched(BandwidthAnomalyDetected::class);
    }
}
