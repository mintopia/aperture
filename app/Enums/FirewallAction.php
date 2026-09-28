<?php

declare(strict_types=1);

namespace App\Enums;

enum FirewallAction: string
{
    case Internet = 'internet';
    case RateLimit = 'rate-limit';
}
