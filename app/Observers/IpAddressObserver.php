<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FirewallAction;
use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncFirewallJob;
use App\Models\IpAddress;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class IpAddressObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(IpAddress $ip): void
    {
        if ($ip->wasChanged('internet_enabled')) {
            // null (no explicit decision) is enforced as blocked — deny-by-default.
            SyncFirewallJob::dispatch($ip, FirewallAction::Internet);
        }

        if ($ip->wasChanged('rate_limit_enabled')) {
            SyncFirewallJob::dispatch($ip, FirewallAction::RateLimit);
        }

        if ($ip->wasChanged('dns_filtering_enabled')) {
            SyncDnsFilteringJob::dispatch($ip->address);
        }
    }
}
