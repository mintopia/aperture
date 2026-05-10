<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Throwable;

class UserNetworkAssociationService
{
    public function __construct(
        private readonly NetworkRangeService $rangeService,
        private readonly IpPolicyService $policyService,
        private readonly IpAddressActionService $actionService,
    ) {}

    public function addIp(User $user, string $clientIp, bool $cascade = true): ?IpAddress
    {
        if (! $this->rangeService->isManaged($clientIp)) {
            return null;
        }

        $ip = IpAddress::whereAddress($clientIp)->first();
        if (! $ip) {
            $ip = new IpAddress;
            $ip->address = $clientIp;
            $ip->last_seen_at = now();
            $ip->save();
        }

        $userIp = $user->ips()->whereIpAddressId($ip->id)->first();
        if (! $userIp) {
            $userIp = new UserIpAddress;
            $userIp->user()->associate($user);
            $userIp->ip()->associate($ip);
        }

        $userIp->last_seen_at = now();
        $userIp->save();

        $this->policyService->applyUserPolicy($user, $ip);

        if ($ip->internet_enabled) {
            try {
                $this->actionService->enableInternet($ip);
            } catch (Throwable) {
                // Firewall sync is best-effort
            }
        }

        if ($cascade) {
            $this->cascadeMacOwnership($user, $ip);
        }

        return $ip;
    }

    /**
     * Assign MAC ownership and cascade IP associations via shared MACs.
     * Depth-limited to one hop (IP -> MAC -> sibling IPs).
     */
    private function cascadeMacOwnership(User $user, IpAddress $ip): void
    {
        $macs = $ip->macAddresses()->get();

        foreach ($macs as $mac) {
            // Assign MAC ownership if unowned
            if ($mac->user_id === null) {
                $mac->user_id = $user->id;
                $mac->save();

                AuditLog::record(
                    action: 'mac.user_assigned',
                    subject: $mac,
                    related: $ip,
                    actor: $user,
                    process: 'portal_login',
                    metadata: ['user_id' => $user->id],
                );
            }

            // Only cascade sibling IPs for MACs we own
            if ((int) $mac->user_id !== (int) $user->id) {
                continue;
            }

            // Find sibling IPs on this MAC (one hop)
            $siblingIps = $mac->ipAddresses()->where('ip_addresses.id', '!=', $ip->id)->get();

            foreach ($siblingIps as $siblingIp) {
                // Skip if another user already owns this IP
                $existingOwner = UserIpAddress::where('ip_address_id', $siblingIp->id)->first();
                if ($existingOwner !== null && (int) $existingOwner->user_id !== (int) $user->id) {
                    continue;
                }

                // Use addIp with cascade=false to prevent recursion
                $cascaded = $this->addIp($user, $siblingIp->address, cascade: false);

                if ($cascaded instanceof IpAddress) {
                    AuditLog::record(
                        action: 'ip.user_cascaded',
                        subject: $siblingIp,
                        related: $mac,
                        actor: $user,
                        process: 'portal_login',
                        metadata: ['source_ip' => $ip->address],
                    );
                }
            }
        }
    }
}
