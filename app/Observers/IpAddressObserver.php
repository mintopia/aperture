<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;

class IpAddressObserver
{
    public function updated(IpAddress $ip): void
    {
        if ($ip->wasChanged('internet_enabled')) {
            SyncInternetAccessJob::dispatch($ip, $ip->internet_enabled);
        }

        if ($ip->wasChanged('rate_limit_enabled')) {
            SyncRateLimitJob::dispatch($ip, $ip->rate_limit_enabled);
        }

        if ($ip->wasChanged('dns_filtering_enabled')) {
            SyncDnsFilteringJob::dispatch($ip->address, $ip->dns_filtering_enabled);
        }
    }
}
