<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Models\IntegrationConfig;
use App\Services\Integration\IntegrationConfigMerger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IntegrationConfigMergerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private IntegrationConfigMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new IntegrationConfigMerger;
    }

    public function test_returns_db_config_when_request_is_empty(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'db-key');

        $result = $this->merger->merge('opnsense', Request::create('/'));

        $this->assertSame('https://opnsense.example.com', $result['endpoint']);
        $this->assertSame('db-key', $result['key']);
    }

    public function test_request_values_override_db_config(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://old.example.com');
        IntegrationConfig::setValue('opnsense', 'key', 'old-key');

        $request = Request::create('/', 'POST', [
            'endpoint' => 'https://new.example.com',
            'key' => 'new-key',
        ]);

        $result = $this->merger->merge('opnsense', $request);

        $this->assertSame('https://new.example.com', $result['endpoint']);
        $this->assertSame('new-key', $result['key']);
    }

    public function test_empty_string_request_values_do_not_override_db(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');

        $request = Request::create('/', 'POST', ['endpoint' => '']);

        $result = $this->merger->merge('opnsense', $request);

        $this->assertSame('https://opnsense.example.com', $result['endpoint']);
    }

    public function test_null_request_values_do_not_override_db(): void
    {
        IntegrationConfig::setValue('opnsense', 'key', 'db-key');

        $request = new Request;
        $request->merge(['key' => null]);

        $result = $this->merger->merge('opnsense', $request);

        $this->assertSame('db-key', $result['key']);
    }

    public function test_keys_not_in_fields_config_are_excluded_from_request(): void
    {
        $request = Request::create('/', 'POST', [
            'endpoint' => 'https://opnsense.example.com',
            'arbitrary_field' => 'should-be-ignored',
            '_token' => 'csrf-token',
        ]);

        $result = $this->merger->merge('opnsense', $request);

        $this->assertSame('https://opnsense.example.com', $result['endpoint']);
        $this->assertArrayNotHasKey('arbitrary_field', $result);
        $this->assertArrayNotHasKey('_token', $result);
    }

    public function test_partial_request_merges_with_full_db_config(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.example.com');
        IntegrationConfig::setValue('borealis', 'client_id', 'db-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'db-secret', true);

        $request = Request::create('/', 'POST', ['client_id' => 'new-client-id']);

        $result = $this->merger->merge('borealis', $request);

        $this->assertSame('https://auth.example.com', $result['endpoint']);
        $this->assertSame('new-client-id', $result['client_id']);
        $this->assertSame('db-secret', $result['client_secret']);
    }

    public function test_returns_empty_array_for_unconfigured_integration_with_no_request(): void
    {
        $result = $this->merger->merge('opnsense', Request::create('/'));

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
