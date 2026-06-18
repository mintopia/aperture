<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpAddressAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, string> */
    private const array ALLOWED_METHODS = [
        'enableInternet',
        'disableInternet',
        'enableRateLimit',
        'disableRateLimit',
    ];

    /**
     * Create a new job instance.
     */
    public function __construct(protected IpAddress $ip, protected string $method)
    {
        //
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    /**
     * Execute the job.
     */
    public function handle(IpAddressActionService $service): void
    {
        if (! in_array($this->method, self::ALLOWED_METHODS, true)) {
            Log::error('Invalid IpAddressAction method', ['method' => $this->method]);

            return;
        }

        $service->{$this->method}($this->ip);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('IpAddressAction failed', [
            'ip' => $this->ip->address,
            'method' => $this->method,
            'error' => $exception->getMessage(),
        ]);
    }
}
