<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IpAddress;
use App\Models\User;

class IpPolicyService
{
    public function applyUserPolicy(User $user, IpAddress $ip): void
    {
        $internetEnabled = $user->internet_blocked ? false : (bool) $user->internet_enabled;

        $ip->internet_enabled = $internetEnabled;
        $ip->rate_limit_enabled = (bool) $user->rate_limit_enabled;
        $ip->dns_filtering_enabled = (bool) $user->dns_filtering_enabled;

        if ($ip->isDirty()) {
            $ip->save();
        }
    }

    public function applyDefaults(IpAddress $ip): void
    {
        $ip->internet_enabled = false;
        $ip->rate_limit_enabled = false;
        $ip->dns_filtering_enabled = false;

        if ($ip->isDirty()) {
            $ip->save();
        }
    }
}
