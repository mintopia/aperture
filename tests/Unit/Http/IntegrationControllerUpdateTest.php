<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Requests\Admin\UpdateIntegrationRequest;
use App\Models\IntegrationConfig;
use App\Services\Integration\DeclaredFieldConfigMerger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class IntegrationControllerUpdateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_update_skips_config_key_not_in_validation_rules(): void
    {
        $request = new class extends UpdateIntegrationRequest
        {
            /** @return array<string, mixed> */
            public function validated($key = null, $default = null): array
            {
                return [
                    'config' => [
                        'endpoint' => 'https://opnsense.example.com',
                        'unknown_extra_key' => 'should-be-skipped',
                    ],
                ];
            }
        };

        $merger = Mockery::mock(DeclaredFieldConfigMerger::class);
        $controller = new IntegrationController($merger);

        $response = $controller->update($request, 'opnsense');

        $this->assertSame(302, $response->getStatusCode());

        $this->assertEquals('https://opnsense.example.com', IntegrationConfig::getValue('opnsense', 'endpoint'));
        $this->assertNull(IntegrationConfig::getValue('opnsense', 'unknown_extra_key'));
    }
}
