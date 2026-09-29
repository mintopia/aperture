<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use Tests\TestCase;

class IntegrationTesterConfigTest extends TestCase
{
    public function test_every_configured_integration_declares_a_resolvable_tester(): void
    {
        $integrations = config('integrations');

        $this->assertIsArray($integrations);

        foreach ($integrations as $key => $definition) {
            $this->assertArrayHasKey('tester', $definition, sprintf('Integration [%s] has no tester', $key));
            $this->assertInstanceOf(TestableIntegration::class, resolve($definition['tester']), sprintf('Integration [%s]', $key));
        }
    }
}
