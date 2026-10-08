<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ResetAperture implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(Queues::SYNC);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        CaptivePortalInterface $captivePortal,
        RateLimitingInterface $rateLimiter,
        DnsFilteringInterface $dnsFiltering,
    ): void {
        // Only undo what Aperture applied; entries added in OPNsense or Pi-hole by hand are left alone.
        // IPs stay in the database until every removal succeeds so a retry knows what is left to undo.
        $errors = [
            ...$this->revert('internet_enabled', $captivePortal->removeIp(...)),
            ...$this->revert('rate_limit_enabled', $rateLimiter->unlimitIp(...)),
            ...$this->revert('dns_filtering_enabled', $dnsFiltering->disableForIp(...)),
        ];

        if ($errors !== []) {
            throw new RuntimeException('Failed to revert IP access during reset: '.implode('; ', $errors));
        }

        UserIpAddress::query()->delete();
        IpAddressMacAddress::query()->delete();
        IpAddress::query()->delete();
        MacAddress::query()->delete();

        $adminIds = User::query()->whereHas('roles', function ($query): void {
            $query->where('code', 'admin');
        })->pluck('id');

        User::query()->whereNotIn('id', $adminIds)->chunkById(100, function (Collection $users): void {
            foreach ($users as $user) {
                $user->delete();
            }
        });

        Log::info('Aperture reset completed');
    }

    /**
     * @param  callable(string): void  $remove
     * @return list<string>
     */
    private function revert(string $flag, callable $remove): array
    {
        $errors = [];

        foreach (IpAddress::query()->where($flag, true)->pluck('address') as $address) {
            try {
                $remove($address);
            } catch (Throwable $e) {
                $errors[] = $address.': '.$e->getMessage();
            }
        }

        return $errors;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ResetAperture failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
