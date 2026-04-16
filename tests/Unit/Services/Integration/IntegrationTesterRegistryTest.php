<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use InvalidArgumentException;
use Tests\TestCase;

class IntegrationTesterRegistryTest extends TestCase
{
    public function test_can_register_and_retrieve_tester(): void
    {
        $tester = $this->createMockTester();
        $registry = new IntegrationTesterRegistry;

        $registry->register('opnsense', $tester);

        $this->assertSame($tester, $registry->get('opnsense'));
    }

    public function test_has_returns_true_for_registered_service(): void
    {
        $registry = new IntegrationTesterRegistry;
        $registry->register('librenms', $this->createMockTester());

        $this->assertTrue($registry->has('librenms'));
    }

    public function test_has_returns_false_for_unregistered_service(): void
    {
        $registry = new IntegrationTesterRegistry;

        $this->assertFalse($registry->has('unknown'));
    }

    public function test_get_throws_for_unregistered_service(): void
    {
        $registry = new IntegrationTesterRegistry;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No tester for: unknown-service');

        $registry->get('unknown-service');
    }

    public function test_pre_populated_via_constructor(): void
    {
        $tester = $this->createMockTester();
        $registry = new IntegrationTesterRegistry(['ntopng' => $tester]);

        $this->assertTrue($registry->has('ntopng'));
        $this->assertSame($tester, $registry->get('ntopng'));
    }

    public function test_register_overwrites_existing_tester(): void
    {
        $first = $this->createMockTester();
        $second = $this->createMockTester();
        $registry = new IntegrationTesterRegistry;

        $registry->register('pihole', $first);
        $registry->register('pihole', $second);

        $this->assertSame($second, $registry->get('pihole'));
    }

    public function test_multiple_services_are_independent(): void
    {
        $opnTester = $this->createMockTester();
        $libreTester = $this->createMockTester();
        $registry = new IntegrationTesterRegistry;

        $registry->register('opnsense', $opnTester);
        $registry->register('librenms', $libreTester);

        $this->assertSame($opnTester, $registry->get('opnsense'));
        $this->assertSame($libreTester, $registry->get('librenms'));
    }

    private function createMockTester(): TestableIntegration
    {
        return new class implements TestableIntegration
        {
            public function connect(array $config): TestConnectionResult
            {
                return new TestConnectionResult(success: true, message: 'ok');
            }
        };
    }
}
