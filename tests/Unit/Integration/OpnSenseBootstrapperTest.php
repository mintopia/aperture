<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\IntegrationBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use PHPUnit\Framework\TestCase;

class OpnSenseBootstrapperTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new OpnSenseBootstrapper);
    }
}
