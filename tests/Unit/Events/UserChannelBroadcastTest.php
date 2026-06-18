<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\DnsFilterChanged;
use App\Events\InternetAccessChanged;
use App\Events\RateLimitChanged;
use App\Events\UserBlocked;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class UserChannelBroadcastTest extends TestCase
{
    /**
     * @param  array<int, int>  $userIds
     */
    private function createIpWithUsers(array $userIds): IpAddress
    {
        $userIps = new Collection(array_map(function (int $userId): UserIpAddress {
            $userIp = $this->createStub(UserIpAddress::class);
            $userIp->method('__get')->willReturnCallback(
                fn (string $key): mixed => match ($key) {
                    'user_id' => $userId,
                    default => null,
                }
            );

            return $userIp;
        }, $userIds));

        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(
            fn (string $key): mixed => match ($key) {
                'users' => $userIps,
                'id' => 1,
                'ip_address' => '10.0.0.1',
                default => null,
            }
        );

        return $ipAddress;
    }

    public function test_internet_access_changed_broadcasts_on_user_channel(): void
    {
        $ipAddress = $this->createIpWithUsers([42]);
        $event = new InternetAccessChanged($ipAddress, true, null);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertSame('private-user.42', $channels[1]->name);
    }

    public function test_internet_access_changed_broadcasts_on_multiple_user_channels(): void
    {
        $ipAddress = $this->createIpWithUsers([42, 99]);
        $event = new InternetAccessChanged($ipAddress, true, null);
        $channels = $event->broadcastOn();

        $this->assertCount(3, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertSame('private-user.42', $channels[1]->name);
        $this->assertSame('private-user.99', $channels[2]->name);
    }

    public function test_dns_filter_changed_broadcasts_on_user_channel(): void
    {
        $ipAddress = $this->createIpWithUsers([10]);
        $event = new DnsFilterChanged($ipAddress, true, null);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertSame('private-user.10', $channels[1]->name);
    }

    public function test_rate_limit_changed_broadcasts_on_user_channel(): void
    {
        $ipAddress = $this->createIpWithUsers([7]);
        $event = new RateLimitChanged($ipAddress, 100, 200, null);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertSame('private-user.7', $channels[1]->name);
    }

    public function test_user_blocked_broadcasts_on_user_channel(): void
    {
        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(
            fn (string $key): mixed => match ($key) {
                'id' => 20,
                'nickname' => 'Test User',
                default => null,
            }
        );

        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(
            fn (string $key): mixed => match ($key) {
                'id' => 1,
                'ip_address' => '10.0.0.1',
                default => null,
            }
        );

        $event = new UserBlocked($user, $ipAddress, 'Terms violation');
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertSame('private-user.20', $channels[1]->name);
    }

    public function test_all_user_channels_are_private(): void
    {
        $ipAddress = $this->createIpWithUsers([1, 2, 3]);
        $event = new InternetAccessChanged($ipAddress, true, null);
        $channels = $event->broadcastOn();

        foreach ($channels as $channel) {
            $this->assertInstanceOf(PrivateChannel::class, $channel);
        }
    }

    public function test_ip_address_without_users_only_broadcasts_on_admin(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(
            fn (string $key): mixed => match ($key) {
                'users' => new Collection,
                'id' => 1,
                'ip_address' => '10.0.0.1',
                default => null,
            }
        );

        $event = new InternetAccessChanged($ipAddress, true, null);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }
}
