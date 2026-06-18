<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\CiscoBootstrapper;
use App\Integration\IntegrationBootstrapper;
use Tests\TestCase;

class CiscoBootstrapperTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new CiscoBootstrapper);
    }
}
