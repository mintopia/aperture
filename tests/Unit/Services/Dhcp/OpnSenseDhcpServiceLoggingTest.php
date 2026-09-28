<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use Illuminate\Support\Facades\Log;
use Tests\Support\Fake;
use Tests\TestCase;

class OpnSenseDhcpServiceLoggingTest extends TestCase
{
    public function test_ipv4_range_failure_is_logged(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Failed to fetch IPv4 DHCP ranges'
                    && isset($context['error'])
                    && isset($context['path'])
                    && $context['path'] === '/api/dhcpv4/ranges';
            });

        Fake::sequence([
            Fake::response(500, [], 'Internal Server Error'),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            endpoint: 'http://opnsense.test',
            key: 'key',
            secret: 'secret',
            poolSize: 254,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            ipv4RangesPath: '/api/dhcpv4/ranges',
            ipv6RangesPath: '',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(0, $ranges);
    }

    public function test_ipv6_range_failure_is_logged(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Failed to fetch IPv6 DHCP ranges'
                    && isset($context['error'])
                    && isset($context['path'])
                    && $context['path'] === '/api/dhcpv6/ranges';
            });

        Fake::sequence([
            Fake::response(500, [], 'Internal Server Error'),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            endpoint: 'http://opnsense.test',
            key: 'key',
            secret: 'secret',
            poolSize: 254,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            ipv4RangesPath: '',
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(0, $ranges);
    }
}
