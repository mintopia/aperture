<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\IpPolicyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class UserIpAddressObserverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_calls_apply_defaults_when_user_ip_address_is_deleted(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['internet_enabled' => true]);

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->mock(IpPolicyService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('applyDefaults')
                ->once()
                ->withArgs(function (IpAddress $argIp) use ($ip): bool {
                    return $argIp->is($ip);
                });
        });

        $userIp->delete();
    }
}
