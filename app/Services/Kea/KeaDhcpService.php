<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Null\NullDhcpService;

/**
 * Distinct from NullDhcpService so KeaBootstrapper can bind Kea specifically.
 */
class KeaDhcpService extends NullDhcpService {}
