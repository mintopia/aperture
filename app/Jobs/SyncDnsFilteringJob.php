<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDnsFilteringJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $ipAddress,
    ) {
        $this->onQueue(Queues::ACCESS);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(static::class.':'.$this->ipAddress))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(DnsFilteringInterface $dnsFiltering): void
    {
        $ip = IpAddress::where('address', $this->ipAddress)->first();
        if ($ip === null) {
            return;
        }

        if ($ip->dns_filtering_enabled) {
            $dnsFiltering->enableForIp($this->ipAddress);
        } else {
            $dnsFiltering->disableForIp($this->ipAddress);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncDnsFilteringJob failed', [
            'ip' => $this->ipAddress,
            'error' => $exception->getMessage(),
        ]);
    }
}
