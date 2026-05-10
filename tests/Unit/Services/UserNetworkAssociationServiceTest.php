<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\IpAddressActionService;
use App\Services\IpPolicyService;
use App\Services\NetworkRangeService;
use App\Services\UserNetworkAssociationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserNetworkAssociationServiceTest extends TestCase
{
    private NetworkRangeService&MockObject $rangeService;

    private IpPolicyService&MockObject $policyService;

    private IpAddressActionService&MockObject $actionService;

    private UserNetworkAssociationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rangeService = $this->createMock(NetworkRangeService::class);
        $this->policyService = $this->createMock(IpPolicyService::class);
        $this->actionService = $this->createMock(IpAddressActionService::class);
        $this->service = new UserNetworkAssociationService(
            $this->rangeService,
            $this->policyService,
            $this->actionService,
        );
    }

    public function test_add_ip_returns_null_when_not_managed(): void
    {
        $this->rangeService->method('isManaged')->with('10.0.0.1')->willReturn(false);
        $user = $this->createMock(User::class);

        $result = $this->service->addIp($user, '10.0.0.1');
        $this->assertNull($result);
    }

    public function test_service_is_constructable_with_di(): void
    {
        $this->assertInstanceOf(UserNetworkAssociationService::class, $this->service);
    }
}
