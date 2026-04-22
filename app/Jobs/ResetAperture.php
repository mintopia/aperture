<?php

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ResetAperture implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        IpAddress::query()->chunk(100, function (Collection $ips): void {
            foreach ($ips as $ip) {
                /** @var IpAddress $ip */
                if ($ip->rate_limit_enabled) {
                    $ip->rate_limit_enabled = false;
                    $ip->saveQuietly();
                }

                $ip->internet_enabled = false;
                $ip->saveQuietly();
                $ip->delete();
            }
        });

        $adminIds = User::query()->whereHas('roles', function ($query): void {
            $query->where('code', 'admin');
        })->pluck('id');

        User::query()->whereNotIn('id', $adminIds)->chunk(100, function (Collection $users): void {
            foreach ($users as $user) {
                $user->delete();
            }
        });

        Log::info('Aperture reset completed');
    }
}
