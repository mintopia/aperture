<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\Admin\IntegrationController;
use App\Models\IntegrationConfig;
use App\Services\Integration\IntegrationConfigMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

/**
 * Unit test covering IntegrationController::update() line 136 —
 * the `continue` branch that fires when a config key is present in the
 * request's validated output but is not in the integration's validationRules.
 *
 * This branch is unreachable via HTTP because Laravel's validate() strips
 * keys that have no declared rule. We must call the controller directly with
 * a crafted Request whose validate() returns extra, undeclared keys.
 */
class IntegrationControllerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_skips_config_key_not_in_validation_rules(): void
    {
        // Craft a Request subclass whose validate() returns a config that
        // includes 'unknown_extra_key' not present in the integration's validationRules
        $request = new class extends Request
        {
            /** @return array<string, mixed> */
            public function validate(array $rules, ...$params): array
            {
                return [
                    'config' => [
                        'endpoint' => 'https://opnsense.example.com',
                        'unknown_extra_key' => 'should-be-skipped',
                    ],
                ];
            }
        };

        $merger = Mockery::mock(IntegrationConfigMerger::class);
        $controller = new IntegrationController($merger);

        // Call update() directly — endpoint is in validationRules, unknown_extra_key is not
        // The continue branch fires for unknown_extra_key
        $response = $controller->update($request, 'opnsense');

        $this->assertSame(302, $response->getStatusCode());

        // Verify that only 'endpoint' was stored, not 'unknown_extra_key'
        $this->assertEquals('https://opnsense.example.com', IntegrationConfig::getValue('opnsense', 'endpoint'));
        $this->assertNull(IntegrationConfig::getValue('opnsense', 'unknown_extra_key'));
    }
}
