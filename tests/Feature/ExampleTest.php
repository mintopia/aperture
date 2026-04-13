<?php

declare(strict_types=1);

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_the_captive_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('captive.index'));
    }
}
