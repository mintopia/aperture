<?php

declare(strict_types=1);

namespace App\Enums;

enum Capability: string
{
    case CaptivePortal = 'captive-portal';
    case RateLimiting = 'rate-limiting';
    case Dhcp = 'dhcp';
    case DnsFiltering = 'dns-filtering';
    case IpBandwidth = 'ip-bandwidth';
    case PortBandwidth = 'port-bandwidth';
    case PortErrors = 'port-errors';
    case IpMac = 'ip-mac';
    case PortMac = 'port-mac';
    case Authentication = 'authentication';
}
