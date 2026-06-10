<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IpMacLinked;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\UserIpAddress;
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
    ) {}

    /**
     * Cascade the MAC's owner onto a newly linked (or refreshed) IP address.
     *
     * See ADR-011: skipped when the MAC is unowned, when the IP belongs to a
     * different user, or when the owner is already associated with the IP.
     */
    public function handle(IpMacLinked $event): void
    {
        $owner = $event->mac->loadMissing('user')->user;
        if ($owner === null) {
            return;
        }

        $existing = UserIpAddress::where('ip_address_id', $event->ip->id)->first();
        if ($existing !== null && (int) $existing->user_id !== (int) $owner->id) {
            return;
        }

        $alreadyAssociated = UserIpAddress::where('ip_address_id', $event->ip->id)
            ->where('user_id', $owner->id)
            ->exists();
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
}
