<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\IpBandwidthInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncUserBandwidthCommand extends Command
{
    protected $signature = 'aperture:sync-user-bandwidth';

    protected $description = 'Update weekly bandwidth totals for all users from the user-bandwidth capability';

    public function handle(IpBandwidthInterface $ipBandwidth): void
    {
        User::query()
            ->whereHas('ips')
            ->with('ips.ip')
            ->chunk(50, function ($users) use ($ipBandwidth): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $this->syncUser($user, $ipBandwidth);
                }
            });
    }

    private function syncUser(User $user, IpBandwidthInterface $ipBandwidth): void
    {
        try {
            /** @var Collection<int, UserIpAddress> $userIps */
            $userIps = $user->ips;

            $ipAddresses = $userIps
                ->map(fn (UserIpAddress $userIp) => $userIp->ip->address)
                ->filter()
                ->values()
                ->all();

            if (empty($ipAddresses)) {
                return;
            }

            $bandwidth = $ipBandwidth->getIpBandwidth($ipAddresses, '7d');
            $user->weekly_received = max(0, $bandwidth->received);
            $user->weekly_sent = max(0, $bandwidth->sent);
            $user->weekly_bandwidth = max(0, $bandwidth->received + $bandwidth->sent);
            $user->save();

            Log::debug(sprintf('[%s] Updated weekly bandwidth: %d bytes (down: %d, up: %d)', $user->nickname, $user->weekly_bandwidth, $user->weekly_received, $user->weekly_sent));
        } catch (Throwable $throwable) {
            Log::warning('Failed to sync user bandwidth', [
                'user_id' => $user->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
