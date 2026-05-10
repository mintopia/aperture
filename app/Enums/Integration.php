<?php

declare(strict_types=1);

namespace App\Enums;

enum Integration: string
{
    case OpnSense = 'opnsense';
    case PiHole = 'pihole';
    case LibreNms = 'librenms';
    case Borealis = 'borealis';
    case Prometheus = 'prometheus';
    case Seatpicker = 'seatpicker';
}
