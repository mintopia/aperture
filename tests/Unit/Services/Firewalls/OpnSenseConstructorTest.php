<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Firewalls;

use App\Services\Firewalls\OpnSense;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OpnSenseConstructorTest extends TestCase
{
    public function test_constructor_accepts_all_parameters(): void
    {
        $opnsense = new OpnSense(
            endpoint: 'https://opnsense.example.com',
            key: 'mykey',
            secret: 'mysecret',
            zoneId: 42,
            verify: false,
            uploadRuleUuid: 'uuid-up',
            downloadRuleUuid: 'uuid-down',
        );

        $reflection = new ReflectionClass($opnsense);

        $zoneIdProp = $reflection->getProperty('zoneId');
        $this->assertEquals(42, $zoneIdProp->getValue($opnsense));

        $uploadProp = $reflection->getProperty('uploadRuleUuid');
        $this->assertEquals('uuid-up', $uploadProp->getValue($opnsense));

        $downloadProp = $reflection->getProperty('downloadRuleUuid');
        $this->assertEquals('uuid-down', $downloadProp->getValue($opnsense));
    }
}
