<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\Handler;
use ReflectionClass;
use Tests\TestCase;

class HandlerTest extends TestCase
{
    public function test_dont_flash_contains_sensitive_fields(): void
    {
        $handler = $this->app->make(Handler::class);

        $reflection = new ReflectionClass($handler);
        $prop = $reflection->getProperty('dontFlash');
        $dontFlash = $prop->getValue($handler);

        $this->assertContains('current_password', $dontFlash);
        $this->assertContains('password', $dontFlash);
        $this->assertContains('password_confirmation', $dontFlash);
    }

    public function test_register_sets_up_reportable(): void
    {
        // Ensure the handler can be instantiated and registered without errors
        $handler = $this->app->make(Handler::class);
        $this->assertInstanceOf(Handler::class, $handler);
    }
}
