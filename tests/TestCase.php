<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected bool $seedSetupUser = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if ($this->seedSetupUser && $this->usesDatabase()) {
            User::factory()->create(['email' => 'setup-seed@test.com']);
        }
    }

    private function usesDatabase(): bool
    {
        return in_array(
            true,
            [
                in_array(RefreshDatabase::class, class_uses_recursive($this), true),
                in_array(LazilyRefreshDatabase::class, class_uses_recursive($this), true),
                in_array(DatabaseMigrations::class, class_uses_recursive($this), true),
                in_array(DatabaseTransactions::class, class_uses_recursive($this), true),
            ],
            true,
        );
    }
}
