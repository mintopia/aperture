<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class IpAddressObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(IpAddress $ip): void
    {
        if ($ip->wasChanged('internet_enabled')) {
            // null (no explicit decision) is enforced as blocked — deny-by-default.
            dispatch(new SyncInternetAccessJob($ip));
        }

        if ($ip->wasChanged('rate_limit_enabled')) {
            dispatch(new SyncRateLimitJob($ip));
        }

        if ($ip->wasChanged('dns_filtering_enabled')) {
            dispatch(new SyncDnsFilteringJob($ip->address));
        }
    }
}
