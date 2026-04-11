<?php

declare(strict_types=1);

namespace App\Services\Borealis;

enum DeviceCodeStatus
{
    case dcsPending;
    case dcsFailed;
    case dcsSuccessful;
}
