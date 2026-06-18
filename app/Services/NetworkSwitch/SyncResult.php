<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchPort;
use App\Models\SwitchSyncRun;

readonly class SyncResult
{
    /**
     * @param  array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}>  $portStateChanges
     */
    public function __construct(
        public SwitchSyncRun $syncRun,
        public array $portStateChanges = [],
    ) {}
}
