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

        if (method_exists($dhcp, 'resetSnapshot')) {
            $dhcp->resetSnapshot();
        }

        $leases = $dhcp->getLeases();
        $ranges = $dhcp->getRanges();
        $poolStatus = $dhcp->getPoolStatus();

        /** @var array{ipv4: bool, ipv6: bool} $fetchStatus */
        $fetchStatus = method_exists($dhcp, 'getFetchStatus')
            ? $dhcp->getFetchStatus()
            : ['ipv4' => true, 'ipv6' => true];

        // Read previous pool utilisation BEFORE the transaction for threshold crossing detection
        $previousUtilisation = DhcpPoolStatusRecord::where('integration', $integration)
            ->where('address_family', 'ipv4')
            ->value('utilisation');
        $previousUtilisation = $previousUtilisation !== null ? (float) $previousUtilisation : null;

        $leasesCount = 0;
        $rangesCount = 0;
        $poolStatusCount = 0;

        DB::transaction(function () use ($integration, $leases, $ranges, $poolStatus, $fetchStatus, &$leasesCount, &$rangesCount, &$poolStatusCount): void {
            $leasesCount = $this->performLeaseSync($integration, $leases, $fetchStatus);
            $rangesCount = $this->performRangeSync($integration, $ranges);
            $poolStatusCount = $this->performPoolStatusSync($integration, $poolStatus);
        });

        $this->fireThresholdEventIfCrossed($integration, $previousUtilisation);

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
    public function fireThresholdEventIfCrossed(string $integration, ?float $previousUtilisation): void
    {
        $currentUtilisation = DhcpPoolStatusRecord::where('integration', $integration)
            ->where('address_family', 'ipv4')
            ->value('utilisation');
        $currentUtilisation = $currentUtilisation !== null ? (float) $currentUtilisation : null;

        if ($currentUtilisation === null || $currentUtilisation < self::UTILISATION_THRESHOLD) {
            return;
        }

        $wasBelowThreshold = $previousUtilisation === null || $previousUtilisation < self::UTILISATION_THRESHOLD;
        if ($wasBelowThreshold) {
            DhcpPoolThresholdReached::dispatch(
                pool: $integration,
                usage: $currentUtilisation,
                threshold: self::UTILISATION_THRESHOLD,
                addressFamily: 'ipv4',
            );
        }
    }

    /**
     * Sync leases from the DHCP provider into the database.
     *
     * Called from within the DB::transaction() closure.
     *
     * @param  Collection<int, DhcpLease>  $leases
     * @param  array{ipv4: bool, ipv6: bool}  $fetchStatus
     */
    public function performLeaseSync(string $integration, Collection $leases, array $fetchStatus): int
    {
        $now = now();
        $addressFamily = 'ipv4';

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => 'leases'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;

        if ($leases->isEmpty()) {
            return $this->handleEmptyResult($syncState, $integration, 'leases', function () use ($integration): void {
                DhcpLeaseModel::where('integration', $integration)->delete();
            });
        }

        $upsertedIds = [];

        foreach ($leases as $lease) {
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
                    'integration' => $integration,
                    'ip_address_id' => $ip->id,
                ],
                [
                    'mac_address_id' => $macAddressId,
                    'hostname' => $lease->hostname,
                    'expires_at' => $lease->expires,
                ],
            );

            $upsertedIds[] = $dhcpLease->id;
        }

        // Delete stale leases for this integration
        DhcpLeaseModel::where('integration', $integration)
            ->whereNotIn('id', $upsertedIds)
            ->delete();

        $syncState->last_success_at = $now;
        $syncState->empty_count = 0;
        $syncState->save();

        return count($upsertedIds);
    }

    /**
     * Sync ranges from the DHCP provider into the database.
     *
     * Called from within the DB::transaction() closure.
     *
     * @param  Collection<int, DhcpRange>  $ranges
     */
    public function performRangeSync(string $integration, Collection $ranges): int
    {
        $now = now();
        $addressFamily = 'ipv4';

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily, 'dataset' => 'ranges'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;

        if ($ranges->isEmpty()) {
            return $this->handleEmptyResult($syncState, $integration, 'ranges', function () use ($integration): void {
                DhcpRangeRecord::where('integration', $integration)->delete();
            });
        }

        $upsertedIds = [];

        foreach ($ranges as $range) {
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
                    'total_addresses' => $range->totalAddresses !== null ? (string) $range->totalAddresses : null,
                    'used_addresses' => $range->usedAddresses !== null ? (string) $range->usedAddresses : null,
                    'utilisation' => $range->utilisation !== null ? (string) $range->utilisation : null,
                ],
            );

            $upsertedIds[] = $record->id;
        }

        // Delete stale ranges for this integration
        DhcpRangeRecord::where('integration', $integration)
            ->whereNotIn('id', $upsertedIds)
            ->delete();

        $syncState->last_success_at = $now;
        $syncState->empty_count = 0;
        $syncState->save();

        return count($upsertedIds);
    }

    /**
     * Sync pool status from the DHCP provider into the database.
     *
     * Called from within the DB::transaction() closure.
     */
    public function performPoolStatusSync(string $integration, DhcpPoolStatus $poolStatus): int
    {
        $now = now();
        $addressFamily = 'ipv4';

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
