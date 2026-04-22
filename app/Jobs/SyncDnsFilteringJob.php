<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Interfaces\DnsFilteringInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncDnsFilteringJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ipAddress,
        public readonly bool $enabled,
    ) {}

    public function handle(DnsFilteringInterface $dnsFiltering): void
    {
        if ($this->enabled) {
            $dnsFiltering->enableForIp($this->ipAddress);
        } else {
            $dnsFiltering->disableForIp($this->ipAddress);
        }
    }
}
