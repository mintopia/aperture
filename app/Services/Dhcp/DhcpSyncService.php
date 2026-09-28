<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Events\DhcpPoolThresholdReached;
use App\Models\DhcpLease as DhcpLeaseModel;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DhcpSyncService
{
    private const UTILISATION_THRESHOLD = 0.8;

    public function sync(string $integration, DhcpSnapshot $snapshot): void
    {
        if ($snapshot->unavailable) {
            Log::info('DhcpSyncService: no active DHCP provider, skipping');

            return;
        }

        $previousUtilisation = [];
        foreach (AddressFamily::cases() as $family) {
            $previousUtilisation[$family->value] = $this->currentUtilisation($integration, $family);
        }

        $counts = ['leases' => 0, 'ranges' => 0, 'pool_status' => 0];

        DB::transaction(function () use ($integration, $snapshot, &$counts): void {
            $counts['leases'] = $this->syncLeases($integration, $snapshot);
            $counts['ranges'] = $this->syncRanges($integration, $snapshot);
            $counts['pool_status'] = $this->syncPoolStatus($integration, $snapshot);
        });

        foreach (AddressFamily::cases() as $family) {
            $this->fireThresholdEventIfCrossed($integration, $family, $previousUtilisation[$family->value]);
        }

        Log::info('SyncDhcpData: sync complete', ['integration' => $integration, ...$counts]);
    }

    private function fireThresholdEventIfCrossed(string $integration, AddressFamily $addressFamily, ?float $previousUtilisation): void
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
                addressFamily: $addressFamily->value,
            );
        }
    }

    private function currentUtilisation(string $integration, AddressFamily $addressFamily): ?float
    {
        $utilisation = DhcpPoolStatusRecord::where('integration', $integration)
            ->where('address_family', $addressFamily->value)
            ->value('utilisation');

        return $utilisation !== null ? (float) $utilisation : null;
    }

    public function syncLeases(string $integration, DhcpSnapshot $snapshot): int
    {
        $count = 0;

        foreach (AddressFamily::cases() as $family) {
            $familyLeases = $snapshot->leases
                ->filter(fn (DhcpLease $lease): bool => AddressFamily::fromIp($lease->ip) === $family)
                ->values();

            $count += $this->syncFamilyLeases($integration, $family, $familyLeases, $snapshot->fetchStatus($family)->leases);
        }

        return $count;
    }

    /**
     * @param  Collection<int, DhcpLease>  $familyLeases
     */
    private function syncFamilyLeases(string $integration, AddressFamily $addressFamily, Collection $familyLeases, bool $fetchSucceeded): int
    {
        if (! $fetchSucceeded) {
            $this->recordFailedAttempt($integration, $addressFamily, 'leases');

            return 0;
        }

        $now = now();

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily->value, 'dataset' => 'leases'],
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
            $normalizedMac = MacAddress::normalize($lease->mac);
            if ($normalizedMac !== null) {
                $mac = MacAddress::firstOrCreate(
                    ['mac_address' => $normalizedMac],
                    ['source' => $lease->macFromDuid ? MacAddress::SOURCE_DHCP_DUID : 'dhcp'],
                );
                $macAddressId = $mac->id;
            }

            $upsertedIds[] = $this->upsertLease($integration, $ip->id, $macAddressId, $lease)->id;
        }

        $this->deleteStaleLeasesForFamily($integration, $addressFamily, $upsertedIds);

        $syncState->last_success_at = $now;
        $syncState->empty_count = 0;
        $syncState->save();

        return count($upsertedIds);
    }

    private function upsertLease(string $integration, int $ipAddressId, ?int $macAddressId, DhcpLease $lease): DhcpLeaseModel
    {
        $key = ['ip_address_id' => $ipAddressId, 'mac_address_id' => $macAddressId];
        $values = [
            'integration' => $integration,
            'hostname' => $lease->hostname,
            'expires_at' => $lease->expires,
        ];

        try {
            return DhcpLeaseModel::updateOrCreate($key, $values);
        } catch (UniqueConstraintViolationException) {
            return DhcpLeaseModel::updateOrCreate($key, $values);
        }
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function deleteStaleLeasesForFamily(string $integration, AddressFamily $addressFamily, array $keepIds): void
    {
        DhcpLeaseModel::where('integration', $integration)
            ->whereNotIn('id', $keepIds)
            ->whereHas('ipAddress', function ($query) use ($addressFamily): void {
                $query->where('address', $addressFamily === AddressFamily::IPv6 ? 'like' : 'not like', '%:%');
            })
            ->delete();
    }

    private function syncRanges(string $integration, DhcpSnapshot $snapshot): int
    {
        $count = 0;

        foreach (AddressFamily::cases() as $family) {
            $familyRanges = $snapshot->ranges
                ->filter(fn (DhcpRange $range): bool => $range->type === $family)
                ->values();

            $count += $this->syncFamilyRanges($integration, $family, $familyRanges, $snapshot->fetchStatus($family)->rangesUsable());
        }

        return $count;
    }

    /**
     * @param  Collection<int, DhcpRange>  $familyRanges
     */
    private function syncFamilyRanges(string $integration, AddressFamily $addressFamily, Collection $familyRanges, bool $fetchSucceeded): int
    {
        if (! $fetchSucceeded) {
            $this->recordFailedAttempt($integration, $addressFamily, 'ranges');

            return 0;
        }

        $now = now();

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily->value, 'dataset' => 'ranges'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;

        if ($familyRanges->isEmpty()) {
            return $this->handleEmptyResult($syncState, $integration, 'ranges', function () use ($integration, $addressFamily): void {
                DhcpRangeRecord::where('integration', $integration)
                    ->where('type', $addressFamily->value)
                    ->delete();
            });
        }

        $upsertedIds = [];

        foreach ($familyRanges as $range) {
            $record = DhcpRangeRecord::updateOrCreate(
                [
                    'integration' => $integration,
                    'type' => $range->type->value,
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
    private function deleteStaleRanges(string $integration, AddressFamily $addressFamily, array $upsertedIds): void
    {
        DhcpRangeRecord::where('integration', $integration)
            ->where('type', $addressFamily->value)
            ->whereNotIn('id', $upsertedIds)
            ->delete();
    }

    private function syncPoolStatus(string $integration, DhcpSnapshot $snapshot): int
    {
        $count = 0;

        foreach (AddressFamily::cases() as $family) {
            $count += $this->syncFamilyPoolStatus($integration, $family, $snapshot->poolStatus($family), $snapshot->fetchStatus($family)->rangesUsable());
        }

        return $count;
    }

    private function syncFamilyPoolStatus(string $integration, AddressFamily $addressFamily, DhcpPoolStatus $poolStatus, bool $fetchSucceeded): int
    {
        if (! $fetchSucceeded) {
            $this->recordFailedAttempt($integration, $addressFamily, 'pool_status');

            return 0;
        }

        if ($poolStatus->total <= 0 && $poolStatus->used <= 0) {
            DhcpPoolStatusRecord::where('integration', $integration)
                ->where('address_family', $addressFamily->value)
                ->delete();

            return 0;
        }

        $now = now();

        $total = (string) $poolStatus->total;
        $used = (string) $poolStatus->used;

        $available = bccomp(bcsub($total, $used, 0), '0', 0) >= 0
            ? bcsub($total, $used, 0)
            : '0';

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
                'address_family' => $addressFamily->value,
            ],
            [
                'total' => $total,
                'used' => $used,
                'available' => $available,
                'utilisation' => $utilisation,
                'synced_at' => $now,
            ],
        );

        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily->value, 'dataset' => 'pool_status'],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = $now;
        $syncState->last_success_at = $now;
        $syncState->save();

        return 1;
    }

    private function recordFailedAttempt(string $integration, AddressFamily $addressFamily, string $dataset): void
    {
        $syncState = DhcpSyncState::firstOrCreate(
            ['integration' => $integration, 'address_family' => $addressFamily->value, 'dataset' => $dataset],
            ['empty_count' => 0],
        );
        $syncState->last_attempt_at = now();
        $syncState->save();
    }

    /**
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
