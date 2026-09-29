<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\BandwidthRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserBlockRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Requests\Admin\UserInternetRequest;
use App\Http\Requests\Admin\UserLimitRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserFormRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_index_request_authorizes(): void
    {
        $request = new UserIndexRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_user_index_request_rules(): void
    {
        $request = new UserIndexRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('perPage', $rules);
        $this->assertStringContainsString('sometimes', $rules['perPage']);
        $this->assertStringContainsString('integer', $rules['perPage']);
        $this->assertStringContainsString('min:1', $rules['perPage']);
        $this->assertStringContainsString('max:100', $rules['perPage']);
    }

    public function test_update_user_request_authorizes(): void
    {
        $request = new UpdateUserRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_update_user_request_rules_without_password(): void
    {
        $request = UpdateUserRequest::create('/test', 'POST', [
            'nickname' => 'Test',
            'email' => 'test@example.com',
        ]);

        $rules = $request->rules();

        $this->assertArrayHasKey('nickname', $rules);
        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('roles', $rules);
        $this->assertArrayHasKey('roles.*', $rules);
        $this->assertArrayNotHasKey('password', $rules);
    }

    public function test_update_user_request_rules_with_password(): void
    {
        $request = UpdateUserRequest::create('/test', 'POST', [
            'nickname' => 'Test',
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $rules = $request->rules();

        $this->assertArrayHasKey('password', $rules);
        $this->assertStringContainsString('min:8', $rules['password']);
        $this->assertStringContainsString('confirmed', $rules['password']);
    }

    public function test_user_block_request_authorizes(): void
    {
        $request = new UserBlockRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_user_block_request_rules(): void
    {
        $request = new UserBlockRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('block', $rules);
        $this->assertStringContainsString('required', $rules['block']);
        $this->assertStringContainsString('boolean', $rules['block']);
    }

    public function test_user_internet_request_authorizes(): void
    {
        $request = new UserInternetRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_user_internet_request_rules(): void
    {
        $request = new UserInternetRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('enable', $rules);
        $this->assertStringContainsString('required', $rules['enable']);
        $this->assertStringContainsString('boolean', $rules['enable']);
    }

    public function test_user_limit_request_authorizes(): void
    {
        $request = new UserLimitRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_user_limit_request_rules(): void
    {
        $request = new UserLimitRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('limit', $rules);
        $this->assertStringContainsString('required', $rules['limit']);
        $this->assertStringContainsString('boolean', $rules['limit']);
    }

    public function test_bandwidth_request_authorizes(): void
    {
        $request = new BandwidthRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_bandwidth_request_rules(): void
    {
        $request = new BandwidthRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('range', $rules);
        $this->assertStringContainsString('nullable', $rules['range']);
        $this->assertStringContainsString('in:1h,24h,4d', $rules['range']);
    }
}
