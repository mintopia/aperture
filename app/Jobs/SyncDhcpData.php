<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\DhcpPoolThresholdReached;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease as DhcpLeaseModel;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncDhcpData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60];

    private const UTILISATION_THRESHOLD = 0.8;

    public function handle(DhcpInterface $dhcp): void
    {
        $assignment = CapabilityAssignment::where('capability', 'dhcp')->first();
        if ($assignment === null) {
            Log::info('SyncDhcpData: no DHCP capability assigned, skipping');

            return;
        }

        if ($dhcp instanceof NullDhcpService) {
            Log::info('SyncDhcpData: no active DHCP provider, skipping');

            return;
        }

        $integration = $assignment->integration;

        Log::info('SyncDhcpData: starting sync', ['integration' => $integration]);

        $dhcp->resetSnapshot();

        $leases = $dhcp->getLeases();
        $ranges = $dhcp->getRanges();
        $ipv4PoolStatus = $dhcp->getPoolStatus('ipv4');
        $ipv6PoolStatus = $dhcp->getPoolStatus('ipv6');
        $fetchStatus = $dhcp->getFetchStatus();

        // Read previous pool utilisation BEFORE the transaction for threshold crossing detection
        $previousUtilisation = [
            'ipv4' => $this->currentUtilisation($integration, 'ipv4'),
            'ipv6' => $this->currentUtilisation($integration, 'ipv6'),
        ];

        $leasesCount = 0;
        $rangesCount = 0;
        $poolStatusCount = 0;

        DB::transaction(function () use ($integration, $leases, $ranges, $ipv4PoolStatus, $ipv6PoolStatus, $fetchStatus, &$leasesCount, &$rangesCount, &$poolStatusCount): void {
            $leasesCount = $this->performLeaseSync($integration, $leases, $fetchStatus);
            $rangesCount = $this->performRangeSync($integration, $ranges, $fetchStatus);
            $poolStatusCount = $this->performPoolStatusSync($integration, $ipv4PoolStatus, $ipv6PoolStatus, $fetchStatus);
        });

        $this->fireThresholdEventIfCrossed($integration, 'ipv4', $previousUtilisation['ipv4']);
        $this->fireThresholdEventIfCrossed($integration, 'ipv6', $previousUtilisation['ipv6']);

        Log::info('SyncDhcpData: sync complete', [
            'integration' => $integration,
            'leases' => $leasesCount,
            'ranges' => $rangesCount,
            'pool_status' => $poolStatusCount,
        ]);
    }

    /**
     * Fire threshold event if utilisation crossed the threshold.
     *
     * Called directly from handle() after the transaction commits.
     */
    public function fireThresholdEventIfCrossed(string $integration, string $addressFamily, ?float $previousUtilisation): void
    {
        $currentUtilisation = $this->currentUtilisation($integration, $addressFamily);

        if ($currentUtilisation === null || $currentUtilisation < self::UTILISATION_THRESHOLD) {
            return;
        }

        $wasBelowThreshold = $previousUtilisation === null || $previousUtilisation < self::UTILISATION_THRESHOLD;
        if ($wasBelowThreshold) {
            DhcpPoolThresholdReached::dispatch(
                pool: $integration,
                usage: $currentUtilisation,
                threshold: self::UTILISATION_THRESHOLD,
                addressFamily: $addressFamily,
            );
        }
    }

    private function currentUtilisation(string $integration, string $addressFamily): ?float
    {
        $utilisation = DhcpPoolStatusRecord::where('integration', $integration)
            ->where('address_family', $addressFamily)
            ->value('utilisation');

        return $utilisation !== null ? (float) $utilisation : null;
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    public function performLeaseSync(string $integration, Collection $leases, array $fetchStatus): int
    {
        $ipv4Leases = $leases->filter(fn (DhcpLease $lease): bool => ! $this->isIpv6($lease->ip))->values();
        $ipv6Leases = $leases->filter(fn (DhcpLease $lease): bool => $this->isIpv6($lease->ip))->values();

        $count = $this->performLeaseSyncForFamily($integration, 'ipv4', $ipv4Leases, $fetchStatus['ipv4']);

        return $count + $this->performLeaseSyncForFamily($integration, 'ipv6', $ipv6Leases, $fetchStatus['ipv6']);
    }

    /**
     * @param  Collection<int, DhcpLease>  $familyLeases
     */
    private function performLeaseSyncForFamily(string $integration, string $addressFamily, Collection $familyLeases, bool $fetchSucceeded): int
    {
        if (! $fetchSucceeded) {
            $this->recordFailedAttempt($integration, $addressFamily, 'leases');

            return 0;
        }

        $now = now();

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => 'leases'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;

        if ($familyLeases->isEmpty()) {
            return $this->handleEmptyResult($syncState, $integration, 'leases', function () use ($integration, $addressFamily): void {
                $this->deleteStaleLeasesForFamily($integration, $addressFamily, []);
            });
        }

        $upsertedIds = [];

        foreach ($familyLeases as $lease) {
            $ip = IpAddress::firstOrCreate(
                ['address' => IpAddress::normalize($lease->ip)],
                ['last_seen_at' => now()],
            );

            $macAddressId = null;
            if ($lease->mac !== null) {
                $normalized = MacAddress::normalize($lease->mac);
                $mac = MacAddress::firstOrCreate(
                    ['mac_address' => $normalized],
                    ['source' => 'dhcp'],
                );
                $macAddressId = $mac->id;
            }

            $dhcpLease = DhcpLeaseModel::updateOrCreate(
                [
                    'ip_address_id' => $ip->id,
                    'mac_address_id' => $macAddressId,
                ],
                [
                    'integration' => $integration,
                    'hostname' => $lease->hostname,
                    'expires_at' => $lease->expires,
                ],
            );

            $upsertedIds[] = $dhcpLease->id;
        }

        $this->deleteStaleLeasesForFamily($integration, $addressFamily, $upsertedIds);

        $syncState->last_success_at = $now;
        $syncState->empty_count = 0;
        $syncState->save();

        return count($upsertedIds);
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function deleteStaleLeasesForFamily(string $integration, string $addressFamily, array $keepIds): void
    {
        DhcpLeaseModel::where('integration', $integration)
            ->whereNotIn('id', $keepIds)
            ->whereHas('ipAddress', function ($query) use ($addressFamily): void {
                if ($addressFamily === 'ipv6') {
                    $query->where('address', 'like', $this->ipv6LikePattern());
                } else {
                    $query->where('address', 'not like', $this->ipv6LikePattern());
                }
            })
            ->delete();
    }

    private const IPV6_MARKER = ':';

    private function isIpv6(string $ip): bool
    {
        return str_contains($ip, self::IPV6_MARKER);
    }

    private function ipv6LikePattern(): string
    {
        return '%'.self::IPV6_MARKER.'%';
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    public function performRangeSync(string $integration, Collection $ranges, array $fetchStatus): int
    {
        $ipv4Ranges = $ranges->filter(fn (DhcpRange $range): bool => $range->type === 'ipv4')->values();
        $ipv6Ranges = $ranges->filter(fn (DhcpRange $range): bool => $range->type === 'ipv6')->values();

        $count = $this->performRangeSyncForFamily($integration, 'ipv4', $ipv4Ranges, $fetchStatus);

        return $count + $this->performRangeSyncForFamily($integration, 'ipv6', $ipv6Ranges, $fetchStatus);
    }

    /**
     * @param  Collection<int, DhcpRange>  $familyRanges
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    private function performRangeSyncForFamily(string $integration, string $addressFamily, Collection $familyRanges, array $fetchStatus): int
    {
        if (! $this->rangesFetchSucceededForFamily($fetchStatus, $addressFamily)) {
            $this->recordFailedAttempt($integration, $addressFamily, 'ranges');

            return 0;
        }

        $now = now();

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => 'ranges'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;

        if ($familyRanges->isEmpty()) {
            return $this->handleEmptyResult($syncState, $integration, 'ranges', function () use ($integration, $addressFamily): void {
                DhcpRangeRecord::where('integration', $integration)
                    ->where('type', $addressFamily)
                    ->delete();
            });
        }

        $upsertedIds = [];

        foreach ($familyRanges as $range) {
            $record = DhcpRangeRecord::updateOrCreate(
                [
                    'integration' => $integration,
                    'type' => $range->type,
                    'interface' => $range->interface,
                    'subnet' => $range->subnet ?? '',
                    'range_from' => $range->rangeFrom ?? '',
                    'range_to' => $range->rangeTo ?? '',
                ],
                [
                    'prefix' => $range->prefix,
                    'gateway' => $range->gateway,
                    'description' => $range->description,
                    'total_addresses' => $range->totalAddresses,
                    'used_addresses' => $range->usedAddresses !== null ? (string) $range->usedAddresses : null,
                    'utilisation' => $range->utilisation !== null ? (string) $range->utilisation : null,
                ],
            );

            $upsertedIds[] = $record->id;
        }

        $this->deleteStaleRanges($integration, $addressFamily, $upsertedIds);

        $syncState->last_success_at = $now;
        $syncState->empty_count = 0;
        $syncState->save();

        return count($upsertedIds);
    }

    /**
     * @param  list<int>  $upsertedIds
     */
    private function deleteStaleRanges(string $integration, string $addressFamily, array $upsertedIds): void
    {
        DhcpRangeRecord::where('integration', $integration)
            ->where('type', $addressFamily)
            ->whereNotIn('id', $upsertedIds)
            ->delete();
    }

    /**
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    public function performPoolStatusSync(string $integration, DhcpPoolStatus $ipv4PoolStatus, DhcpPoolStatus $ipv6PoolStatus, array $fetchStatus): int
    {
        $count = $this->performPoolStatusSyncForFamily($integration, 'ipv4', $ipv4PoolStatus, $fetchStatus, familyIsConfigured: true);

        return $count + $this->performPoolStatusSyncForFamily($integration, 'ipv6', $ipv6PoolStatus, $fetchStatus, familyIsConfigured: $this->hasIpv6PoolData($ipv6PoolStatus));
    }

    private function hasIpv6PoolData(DhcpPoolStatus $ipv6PoolStatus): bool
    {
        return $ipv6PoolStatus->total > 0 || $ipv6PoolStatus->used > 0;
    }

    /**
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    private function performPoolStatusSyncForFamily(string $integration, string $addressFamily, DhcpPoolStatus $poolStatus, array $fetchStatus, bool $familyIsConfigured): int
    {
        if (! $this->rangesFetchSucceededForFamily($fetchStatus, $addressFamily)) {
            $this->recordFailedAttempt($integration, $addressFamily, 'pool_status');

            return 0;
        }

        if ($this->shouldSkipUnconfiguredIpv6PoolStatus($addressFamily, $familyIsConfigured)) {
            DhcpPoolStatusRecord::where('integration', $integration)
                ->where('address_family', $addressFamily)
                ->delete();

            return 0;
        }

        $now = now();

        $total = (string) $poolStatus->total;
        $used = (string) $poolStatus->used;

        // BCMath: available = max(0, total - used)
        $available = bccomp(bcsub($total, $used, 0), '0', 0) >= 0
            ? bcsub($total, $used, 0)
            : '0';

        // Clamp utilisation between 0 and 1
        $utilisation = bccomp($total, '0', 0) > 0
            ? bcdiv($used, $total, 4)
            : '0.0000';

        if (bccomp($utilisation, '1.0000', 4) > 0) {
            $utilisation = '1.0000';
        }

        if (bccomp($utilisation, '0.0000', 4) < 0) {
            $utilisation = '0.0000';
        }

        DhcpPoolStatusRecord::updateOrCreate(
            [
                'integration' => $integration,
                'address_family' => $addressFamily,
            ],
            [
                'total' => $total,
                'used' => $used,
                'available' => $available,
                'utilisation' => $utilisation,
                'synced_at' => $now,
            ],
        );

        // Update sync state
        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => 'pool_status'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;
        $syncState->last_success_at = $now;
        $syncState->save();

        return 1;
    }

    /**
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    private function rangesFetchSucceededForFamily(array $fetchStatus, string $addressFamily): bool
    {
        return $fetchStatus[$addressFamily] && ($fetchStatus[$addressFamily.'_ranges'] ?? true);
    }

    private function shouldSkipUnconfiguredIpv6PoolStatus(string $addressFamily, bool $familyIsConfigured): bool
    {
        return $addressFamily === 'ipv6' && ! $familyIsConfigured;
    }

    private function recordFailedAttempt(string $integration, string $addressFamily, string $dataset): void
    {
        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => $dataset],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = now();
        $syncState->save();
    }

    /**
     * Handle empty result with the safety guard.
     *
     * @param  Closure():void  $deletionCallback
     */
    private function handleEmptyResult(DhcpSyncState $syncState, string $integration, string $dataset, Closure $deletionCallback): int
    {
        $hadData = $syncState->last_success_at !== null;
        if ($hadData) {
            $syncState->empty_count++;
            if ($syncState->empty_count < 3) {
                Log::warning(sprintf('SyncDhcpData: empty %s result, skipping deletion', $dataset), [
                    'integration' => $integration,
                    'empty_count' => $syncState->empty_count,
                ]);
                $syncState->save();

                return 0;
            }

            // 3+ consecutive empties: proceed with deletion
            Log::warning(sprintf('SyncDhcpData: 3 consecutive empty %s results, proceeding with deletion', $dataset), [
                'integration' => $integration,
            ]);
            $deletionCallback();
            $syncState->empty_count = 0;
            $syncState->save();

            return 0;
        }

        $syncState->save();

        return 0;
    }
}
