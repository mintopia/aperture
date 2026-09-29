<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class EnsureSetupCompleteTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_middleware_redirects_to_setup_when_no_users(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect('/setup');
    }

    public function test_middleware_allows_request_when_users_exist(): void
    {
        User::factory()->create();

        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_middleware_returns_503_for_api_when_no_users(): void
    {
        $response = $this->getJson('/api/captive-portal');

        $response->assertStatus(503);
        $response->assertJson([
            'message' => 'Application setup is not complete.',
        ]);
    }

    public function test_setup_routes_excluded_from_middleware(): void
    {
        $response = $this->get('/setup');

        $response->assertOk();
    }

    public function test_database_unavailable_is_tolerated(): void
    {
        DB::select('select 1');
        DB::listen(fn ($q): null => str_contains($q->sql, '"users"') ? throw new QueryException('sqlite', $q->sql, [], new PDOException('unavailable')) : null);

        $this->get('/login')->assertOk();
    }

    public function test_other_errors_propagate(): void
    {
        $this->withoutExceptionHandling();
        DB::select('select 1');
        DB::listen(fn ($q): null => str_contains($q->sql, '"users"') ? throw new RuntimeException('boom') : null);

        $this->expectException(RuntimeException::class);

        $this->get('/login');
    }
}
