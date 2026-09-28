<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IpMacLinked;
use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\IpPolicyService;
use App\Services\UserNetworkAssociationService;

class CascadeMacOwnershipOnLink
{
    /**
     * The association service is constructor-injected; the listener itself is
     * container-resolved per event, so this does not create a circular
     * dependency with IpAddressActionService (which dispatches IpMacLinked).
     */
    public function __construct(
        private readonly UserNetworkAssociationService $associationService,
        private readonly IpPolicyService $policyService,
    ) {}

    /**
     * Cascade the MAC's owner onto a newly linked (or refreshed) IP address.
     *
     * See ADR-011: DUID-derived MACs never drive ownership, and a DHCP lease
     * held by a different owner (or an unowned MAC) takes the IP over once the
     * previous owner's MACs no longer hold a lease on it.
     */
    public function handle(IpMacLinked $event): void
    {
        if ($event->source === MacAddress::SOURCE_DHCP_DUID) {
            return;
        }

        $owner = $event->mac->loadMissing('user')->user;
        $existing = UserIpAddress::where('ip_address_id', $event->ip->id)->first();

        if ($existing !== null && (int) $existing->user_id !== ($owner === null ? 0 : (int) $owner->id)) {
            if ($event->source === 'dhcp' && ! $this->previousOwnerStillHoldsLease($event, (int) $existing->user_id)) {
                $this->reassign($event, $owner);
            }

            return;
        }

        if ($owner === null) {
            return;
        }

        $alreadyAssociated = $existing !== null;
        if ($alreadyAssociated) {
            return;
        }

        $cascaded = $this->associationService->addIp($owner, $event->ip->address, cascade: false);

        if ($cascaded instanceof IpAddress) {
            AuditLog::record(
                action: 'ip.user_cascaded',
                subject: $event->ip,
                related: $event->mac,
                actor: $owner,
                process: $event->process,
                metadata: ['source' => $event->source, 'mac' => $event->mac->mac_address],
            );
        }
    }

    private function previousOwnerStillHoldsLease(IpMacLinked $event, int $previousUserId): bool
    {
        return DhcpLease::where('ip_address_id', $event->ip->id)
            ->where('mac_address_id', '!=', $event->mac->id)
            ->whereHas('macAddress', fn ($query) => $query->where('user_id', $previousUserId))
            ->exists();
    }

    private function reassign(IpMacLinked $event, ?User $newOwner): void
    {
        $previousUserIds = UserIpAddress::where('ip_address_id', $event->ip->id)->pluck('user_id')->all();
        UserIpAddress::where('ip_address_id', $event->ip->id)->delete();

        if ($newOwner === null) {
            $this->policyService->applyDefaults($event->ip);
        } else {
            $this->associationService->addIp($newOwner, $event->ip->address, cascade: false);
        }

        AuditLog::record(
            action: 'ip.user_reassigned',
            subject: $event->ip,
            related: $event->mac,
            actor: $newOwner,
            process: $event->process,
            metadata: [
                'source' => $event->source,
                'mac' => $event->mac->mac_address,
                'previous_user_ids' => $previousUserIds,
                'new_user_id' => $newOwner?->id,
            ],
        );
    }
}
