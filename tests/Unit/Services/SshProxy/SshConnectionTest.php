<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SshProxy;

use App\Services\SshProxy\SshConnection;
use Carbon\CarbonImmutable;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class SshConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_constructor_sets_hostname_and_timestamps(): void
    {
        $now = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);
        CarbonImmutable::setTestNow($now);

        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $this->assertSame('switch01.example.com', $connection->getHostname());
        $this->assertTrue($now->equalTo($connection->getCreatedAt()));
        $this->assertTrue($now->equalTo($connection->getLastUsedAt()));
    }

    public function test_get_ssh_returns_ssh2_instance(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $this->assertSame($ssh, $connection->getSsh());
    }

    public function test_touch_updates_last_used_at(): void
    {
        $now = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);
        CarbonImmutable::setTestNow($now);

        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $this->assertTrue($now->equalTo($connection->getLastUsedAt()));

        $later = $now->addSeconds(30);
        CarbonImmutable::setTestNow($later);

        $connection->touch();

        $this->assertTrue($later->equalTo($connection->getLastUsedAt()));
    }

    public function test_is_idle_returns_true_when_exceeded_timeout(): void
    {
        $now = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);
        CarbonImmutable::setTestNow($now);

        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $later = $now->addSeconds(600);
        CarbonImmutable::setTestNow($later);

        $this->assertTrue($connection->isIdle(600));
    }

    public function test_is_idle_returns_false_when_recently_used(): void
    {
        $now = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);
        CarbonImmutable::setTestNow($now);

        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $this->assertFalse($connection->isIdle(600));
    }

    public function test_lock_and_unlock_toggle_state(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $connection = new SshConnection('switch01.example.com', $ssh);

        $this->assertFalse($connection->isLocked());

        $connection->lock();
        $this->assertTrue($connection->isLocked());

        $connection->unlock();
        $this->assertFalse($connection->isLocked());
    }

    public function test_disconnect_calls_ssh_disconnect(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('disconnect')->once();

        $connection = new SshConnection('switch01.example.com', $ssh);
        $connection->disconnect();
    }
}
