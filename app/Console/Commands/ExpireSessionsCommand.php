<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ExpireSessionsCommand extends Command
{
    protected $signature = 'aperture:expire-sessions';

    protected $description = 'Expire IP sessions that have passed their TTL';

    public function handle(): int
    {
        $count = 0;

        IpAddress::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunk(100, function (Collection $ips) use (&$count): void {
                foreach ($ips as $ip) {
                    /** @var IpAddress $ip */
                    if ($ip->limited) {
                        $ip->unlimit();
                    }

                    $ip->deny();
                    $ip->delete();
                    $count++;
                }
            });

        Log::info('Expired sessions', ['count' => $count]);

        return self::SUCCESS;
    }
}
