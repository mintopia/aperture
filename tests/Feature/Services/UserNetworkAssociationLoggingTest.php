<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpAddressActionService;
use App\Services\IpPolicyService;
use App\Services\NetworkRangeService;
use App\Services\UserNetworkAssociationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class UserNetworkAssociationLoggingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_firewall_enable_failure_is_logged_as_warning_and_association_continues(): void
    {
        $rangeService = $this->createStub(NetworkRangeService::class);
        $rangeService->method('isManaged')->willReturn(true);

        $actionService = $this->createStub(IpAddressActionService::class);
        $actionService->method('enableInternet')->willThrowException(new RuntimeException('firewall unreachable'));

        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['internet_enabled' => true]);

        Log::spy();

        $service = new UserNetworkAssociationService(
            $rangeService,
            $this->createStub(IpPolicyService::class),
            $actionService,
        );

        $result = $service->addIp($user, $ip->address, cascade: false);

        $this->assertInstanceOf(IpAddress::class, $result);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Firewall enable failed during user association'
                && $context['error'] === 'firewall unreachable'
                && $context['user_id'] === $user->id);
    }
}
