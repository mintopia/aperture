<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

enum SnoopingFetchStatus
{
    case Fetched;
    case Unsupported;
    case Failed;
}
