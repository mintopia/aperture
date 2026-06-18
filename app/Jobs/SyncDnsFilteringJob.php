<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Interfaces\DnsFilteringInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDnsFilteringJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $ipAddress,
        public readonly bool $enabled,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(DnsFilteringInterface $dnsFiltering): void
    {
        if ($this->enabled) {
            $dnsFiltering->enableForIp($this->ipAddress);
        } else {
            $dnsFiltering->disableForIp($this->ipAddress);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncDnsFilteringJob failed', [
            'ip' => $this->ipAddress,
            'enabled' => $this->enabled,
            'error' => $exception->getMessage(),
        ]);
    }
}
