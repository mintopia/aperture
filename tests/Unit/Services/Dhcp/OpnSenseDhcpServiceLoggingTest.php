<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
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

        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'http_errors' => true]);

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 254,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            ipv4RangesPath: '/api/dhcpv4/ranges',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

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

        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'http_errors' => true]);

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 254,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            ipv4RangesPath: '',
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }
}
