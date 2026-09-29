<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\ToggleCapabilityRequest;
use Illuminate\Validation\Rules\Enum;
use Tests\TestCase;

class IntegrationFormRequestTest extends TestCase
{
    public function test_toggle_capability_request_authorizes(): void
    {
        $request = new ToggleCapabilityRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_toggle_capability_request_rules(): void
    {
        $request = new ToggleCapabilityRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('capability', $rules);
        $this->assertArrayHasKey('integration', $rules);
        $this->assertArrayHasKey('active', $rules);
        $this->assertSame('required', $rules['capability'][0]);
        $this->assertInstanceOf(Enum::class, $rules['capability'][1]);
        $this->assertStringContainsString('required', $rules['integration']);
        $this->assertStringContainsString('string', $rules['integration']);
        $this->assertStringContainsString('required', $rules['active']);
        $this->assertStringContainsString('boolean', $rules['active']);
    }
}
