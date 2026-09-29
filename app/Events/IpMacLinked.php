<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IpMacLinked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public IpAddress $ip,
        public MacAddress $mac,
        public string $source,
        public string $process,
    ) {}
}
