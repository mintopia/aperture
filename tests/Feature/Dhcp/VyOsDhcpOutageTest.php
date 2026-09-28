<?php

declare(strict_types=1);

namespace Tests\Feature\Dhcp;

use App\Enums\Capability;
use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Services\Interfaces\DhcpInterface;
use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VyOsDhcpOutageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $mode = 'up';

    /**
     * @return array<string, array{string}>
     */
    public static function outageModes(): array
    {
        return [
            'api error' => ['error'],
            'unparseable output' => ['garbage'],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    private function table(array $columns, array $rows): string
    {
        $widths = array_map('strlen', $columns);
        foreach ($rows as $row) {
            foreach ($row as $i => $value) {
                $widths[$i] = max($widths[$i], strlen($value));
            }
        }

        $pad = fn (array $cells): string => implode('  ', array_map(fn (string $c, int $w): string => str_pad($c, $w), $cells, $widths));
        $lines = [$pad($columns), implode('  ', array_map(fn (int $w): string => str_repeat('-', $w), $widths))];
        foreach ($rows as $row) {
            $lines[] = $pad($row);
        }

        return implode("\n", $lines)."\n";
    }

    private function bindService(): void
    {
        CapabilityAssignment::assign(Capability::Dhcp, 'vyos');

        $client = Mockery::mock(VyOsClient::class);
        $client->shouldReceive('showText')->andReturnUsing(function (array $path): string {
            if ($this->mode === 'error') {
                throw new RuntimeException('connection refused');
            }

            if ($this->mode === 'garbage') {
                return '<html>gateway timeout</html>';
            }

            return $path[0] === 'dhcp'
                ? $this->table(['IP Address', 'MAC address', 'Lease expiration', 'Hostname'], [
                    ['10.0.0.10', 'aa:bb:cc:dd:ee:01', '2030-01-01 00:00:00', 'a'],
                    ['10.0.0.11', 'aa:bb:cc:dd:ee:02', '2030-01-01 00:00:00', 'b'],
                ])
                : $this->table(['IPv6 address', 'MAC address', 'Lease expiration', 'Hostname'], [
                    ['2001:db8::5', 'aa:bb:cc:dd:ee:03', '2030-01-01 00:00:00', 'c'],
                ]);
        });
        $client->shouldReceive('retrieve')->andReturnUsing(function (array $path): array {
            if ($this->mode !== 'up') {
                throw new RuntimeException('connection refused');
            }

            return $path[1] === 'dhcp-server'
                ? ['shared-network-name' => ['LAN' => ['subnet' => ['10.0.0.0/24' => ['range' => ['r' => ['start' => '10.0.0.10', 'stop' => '10.0.0.100']]]]]]]
                : ['shared-network-name' => ['LAN6' => ['subnet' => ['2001:db8::/64' => ['range' => ['r' => ['start' => '2001:db8::1', 'stop' => '2001:db8::ffff']]]]]]];
        });

        $this->app->instance(DhcpInterface::class, new VyOsDhcpService($client, 10));
    }

    private function sync(): void
    {
        app()->call([new SyncDhcpData, 'handle']);
    }

    #[DataProvider('outageModes')]
    public function test_repeated_outages_never_delete_stored_data(string $outageMode): void
    {
        $this->bindService();
        $this->sync();

        $this->assertSame(3, DhcpLease::count());
        $this->assertSame(2, DhcpRangeRecord::count());
        $used = DhcpPoolStatusRecord::where('address_family', 'ipv4')->firstOrFail()->used;

        $this->mode = $outageMode;
        for ($i = 0; $i < 4; $i++) {
            $this->sync();
        }

        $this->assertSame(3, DhcpLease::count());
        $this->assertSame(2, DhcpRangeRecord::count());
        $this->assertSame($used, DhcpPoolStatusRecord::where('address_family', 'ipv4')->firstOrFail()->used);
        $this->assertSame(0, DhcpSyncState::where('empty_count', '>', 0)->count());
    }
}
