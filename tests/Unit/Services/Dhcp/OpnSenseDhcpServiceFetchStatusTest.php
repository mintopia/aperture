<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseDhcpServiceFetchStatusTest extends TestCase
{
    /**
     * @param  list<Response|ConnectException>  $responses
     * @param  list<array<string, mixed>>  $history
     */
    private function service(array $responses, array &$history = [], bool $post = false, string $v4 = '', string $v6 = ''): OpnSenseDhcpService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new OpnSenseDhcpService(
            client: new Client(['handler' => $stack]),
            poolSize: 10,
            ipv4RangesPath: $v4,
            ipv6RangesPath: $v6,
            leasesUsePost: $post,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(int $from, int $count): array
    {
        return array_map(fn (int $i): array => [
            'address' => '10.1.'.intdiv($i, 250).'.'.($i % 250 + 1),
            'mac' => 'aa:bb:cc:00:'.sprintf('%02x:%02x', intdiv($i, 256), $i % 256),
            'hostname' => 'h'.$i,
            'ends' => '2030-01-01 00:00:00',
            'status' => 'active',
        ], range($from, $from + $count - 1));
    }

    private function ok(mixed $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    public function test_status_is_all_true_when_nothing_failed(): void
    {
        $service = $this->service([$this->ok(['rows' => []])]);
        $service->getLeases();

        $this->assertSame(['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true], $service->getFetchStatus());
    }

    public function test_lease_failure_marks_both_lease_families_failed(): void
    {
        $service = $this->service([new ConnectException('refused', new Request('GET', 'x'))]);

        $this->assertCount(0, $service->getLeases());
        $status = $service->getFetchStatus();
        $this->assertFalse($status['ipv4']);
        $this->assertFalse($status['ipv6']);
        $this->assertTrue($status['ipv4_ranges']);
    }

    public function test_unparseable_lease_body_is_a_failure(): void
    {
        $service = $this->service([new Response(200, [], 'not json')]);

        $this->assertCount(0, $service->getLeases());
        $this->assertFalse($service->getFetchStatus()['ipv4']);
    }

    public function test_per_family_range_failures_are_tracked_separately(): void
    {
        $service = $this->service([
            $this->ok(['rows' => []]),
            new ConnectException('refused', new Request('GET', 'x')),
        ], v4: '/v4', v6: '/v6');

        $service->getRanges();
        $status = $service->getFetchStatus();

        $this->assertTrue($status['ipv4_ranges']);
        $this->assertFalse($status['ipv6_ranges']);
        $this->assertTrue($status['ipv4']);
    }

    public function test_shared_range_endpoint_failure_marks_both_families(): void
    {
        $service = $this->service([new Response(502)], v4: '/r', v6: '/r');

        $service->getRanges();
        $status = $service->getFetchStatus();

        $this->assertFalse($status['ipv4_ranges']);
        $this->assertFalse($status['ipv6_ranges']);
    }

    public function test_lease_failure_during_range_enrichment_is_recorded(): void
    {
        $range = ['interface' => 'lan', 'subnet' => '10.0.0.0/24', 'range_from' => '10.0.0.1', 'range_to' => '10.0.0.9'];
        $service = $this->service([$this->ok(['rows' => [$range]]), new Response(500)], v4: '/r');

        $service->getRanges();

        $this->assertFalse($service->getFetchStatus()['ipv4']);
    }

    public function test_reset_snapshot_clears_failures(): void
    {
        $service = $this->service([new Response(500)]);
        $service->getLeases();
        $service->resetSnapshot();

        $this->assertSame(['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true], $service->getFetchStatus());
    }

    public function test_kea_mode_pages_through_all_leases(): void
    {
        $history = [];
        $service = $this->service([
            $this->ok(['rows' => $this->rows(0, 100), 'total' => 250]),
            $this->ok(['rows' => $this->rows(100, 100), 'total' => 250]),
            $this->ok(['rows' => $this->rows(200, 50), 'total' => 250]),
        ], $history, post: true);

        $this->assertCount(250, $service->getLeases());
        $this->assertCount(3, $history);
        $this->assertSame(3, json_decode((string) $history[2]['request']->getBody(), true)['current']);
        $this->assertTrue($service->getFetchStatus()['ipv4']);
    }

    public function test_paging_stops_on_short_page_without_total(): void
    {
        $history = [];
        $service = $this->service([
            $this->ok(['rows' => $this->rows(0, 100)]),
            $this->ok(['rows' => $this->rows(100, 7)]),
        ], $history, post: true);

        $this->assertCount(107, $service->getLeases());
        $this->assertCount(2, $history);
    }

    public function test_failure_on_later_page_marks_failed_and_discards_partial_data(): void
    {
        $service = $this->service([
            $this->ok(['rows' => $this->rows(0, 100), 'total' => 250]),
            new Response(500),
        ], post: true);

        $this->assertCount(0, $service->getLeases());
        $this->assertFalse($service->getFetchStatus()['ipv4']);
    }

    public function test_page_cap_marks_failed_instead_of_returning_partial_data(): void
    {
        // total claims far more rows than the page cap allows
        $responses = array_fill(0, 500, $this->ok(['rows' => $this->rows(0, 100), 'total' => 1000000]));
        $service = $this->service($responses, post: true);

        $this->assertCount(0, $service->getLeases());
        $this->assertFalse($service->getFetchStatus()['ipv4']);
    }
}
