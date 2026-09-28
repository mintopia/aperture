<?php

namespace Tests\Unit\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use ReflectionClass;
use Tests\TestCase;

class HandlerTest extends TestCase
{
    public function test_dont_flash_contains_sensitive_fields(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertInstanceOf(Handler::class, $handler);

        $prop = (new ReflectionClass($handler))->getProperty('dontFlash');
        $dontFlash = $prop->getValue($handler);

        $this->assertContains('current_password', $dontFlash);
        $this->assertContains('password', $dontFlash);
        $this->assertContains('password_confirmation', $dontFlash);
    }

    public function test_html_404_renders_inertia_error_page(): void
    {
        $this->get('/definitely-not-a-route')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
    }

    public function test_json_404_is_not_rendered_as_inertia(): void
    {
        $this->getJson('/definitely-not-a-route')->assertNotFound()->assertJsonMissingPath('component');
    }
}
