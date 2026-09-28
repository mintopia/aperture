<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Null\NullIpMacResolver;

/**
 * Distinct from NullIpMacResolver so KeaBootstrapper can bind Kea specifically.
 */
class KeaIpMacResolver extends NullIpMacResolver {}
