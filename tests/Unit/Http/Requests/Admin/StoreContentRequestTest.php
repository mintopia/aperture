<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\StoreContentRequest;
use App\Http\Requests\Admin\UpdateContentRequest;
use Tests\TestCase;

class StoreContentRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $request = new StoreContentRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_title_is_required(): void
    {
        $request = new StoreContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('title', $rules);
        $titleRule = is_array($rules['title']) ? implode('|', $rules['title']) : $rules['title'];
        $this->assertStringContainsString('required', $titleRule);
    }

    public function test_type_is_required(): void
    {
        $request = new StoreContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('type', $rules);
        $typeRules = $rules['type'];
        $this->assertIsArray($typeRules);
        $this->assertContains('required', $typeRules);
    }

    public function test_content_is_nullable(): void
    {
        $request = new StoreContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('content', $rules);
        $contentRule = is_array($rules['content']) ? implode('|', $rules['content']) : $rules['content'];
        $this->assertStringContainsString('nullable', $contentRule);
    }

    public function test_settings_is_nullable_array(): void
    {
        $request = new StoreContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('settings', $rules);
        $settingsRule = is_array($rules['settings']) ? implode('|', $rules['settings']) : $rules['settings'];
        $this->assertStringContainsString('nullable', $settingsRule);
    }

    public function test_update_content_request_authorize_returns_true(): void
    {
        $request = new UpdateContentRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_update_content_request_title_is_sometimes(): void
    {
        $request = new UpdateContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('title', $rules);
        $titleRule = is_array($rules['title']) ? implode('|', $rules['title']) : $rules['title'];
        $this->assertStringContainsString('sometimes', $titleRule);
    }

    public function test_update_content_request_type_is_sometimes(): void
    {
        $request = new UpdateContentRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('type', $rules);
        $typeRule = is_array($rules['type']) ? implode('|', $rules['type']) : $rules['type'];
        $this->assertStringContainsString('sometimes', $typeRule);
    }
}
