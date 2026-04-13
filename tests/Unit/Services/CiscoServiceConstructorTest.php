<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use ReflectionParameter;
use App\Services\CiscoService;
use ReflectionClass;
use Tests\TestCase;

class CiscoServiceConstructorTest extends TestCase
{
    public function test_constructor_accepts_all_credentials(): void
    {
        $ref = new ReflectionClass(CiscoService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $paramNames = array_map(fn (ReflectionParameter $p): string => $p->getName(), $params);

        $this->assertContains('hostname', $paramNames);
        $this->assertContains('username', $paramNames);
        $this->assertContains('password', $paramNames);
        $this->assertContains('enablePassword', $paramNames);
        $this->assertContains('timeout', $paramNames);
    }

    public function test_no_config_calls_in_source(): void
    {
        $source = file_get_contents(app_path('Services/CiscoService.php'));
        $this->assertStringNotContainsString("config('aperture", $source);
        $this->assertStringNotContainsString('config("aperture', $source);
    }
}
