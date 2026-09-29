<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Collection;

readonly class SwitchSnapshot
{
    /**
     * @param  Collection<int, PortStatus>  $portStatuses
     * @param  array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>  $portConfigData
     * @param  Collection<int, ForwardingEntry>  $macEntries
     */
    public function __construct(
        public Collection $portStatuses,
        public array $portConfigData,
        public Collection $macEntries,
        public SnoopingFetchResult $snooping,
    ) {}
}
