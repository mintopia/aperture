<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Firewalls;

use App\Services\Firewalls\OpnSense;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OpnSenseConstructorTest extends TestCase
{
    public function test_constructor_accepts_all_parameters(): void
    {
        $client = new Client([
            'verify' => false,
            'base_uri' => 'https://opnsense.example.com',
            'auth' => ['mykey', 'mysecret'],
        ]);

        $opnsense = new OpnSense(
            client: $client,
            zoneId: 42,
            uploadRuleUuid: 'uuid-up',
            downloadRuleUuid: 'uuid-down',
        );

        $reflection = new ReflectionClass($opnsense);

        $clientProp = $reflection->getProperty('client');
        $this->assertSame($client, $clientProp->getValue($opnsense));

        $zoneIdProp = $reflection->getProperty('zoneId');
        $this->assertEquals(42, $zoneIdProp->getValue($opnsense));

        $uploadProp = $reflection->getProperty('uploadRuleUuid');
        $this->assertEquals('uuid-up', $uploadProp->getValue($opnsense));

        $downloadProp = $reflection->getProperty('downloadRuleUuid');
        $this->assertEquals('uuid-down', $downloadProp->getValue($opnsense));
    }
}
