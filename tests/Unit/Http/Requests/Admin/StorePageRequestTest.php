<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use Tests\TestCase;

class StorePageRequestTest extends TestCase
{
    public function test_store_page_authorize_returns_true(): void
    {
        $request = new StorePageRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_store_page_title_is_required(): void
    {
        $request = new StorePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('title', $rules);
        $titleRule = is_array($rules['title']) ? implode('|', $rules['title']) : $rules['title'];
        $this->assertStringContainsString('required', $titleRule);
    }

    public function test_store_page_slug_is_required_unique(): void
    {
        $request = new StorePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('slug', $rules);
        $slugRule = is_array($rules['slug']) ? implode('|', $rules['slug']) : $rules['slug'];
        $this->assertStringContainsString('required', $slugRule);
        $this->assertStringContainsString('unique', $slugRule);
    }

    public function test_store_page_content_is_nullable(): void
    {
        $request = new StorePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('content', $rules);
        $contentRule = is_array($rules['content']) ? implode('|', $rules['content']) : $rules['content'];
        $this->assertStringContainsString('nullable', $contentRule);
    }

    public function test_update_page_authorize_returns_true(): void
    {
        $request = new UpdatePageRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_update_page_title_is_required(): void
    {
        $request = new UpdatePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('title', $rules);
        $titleRule = is_array($rules['title']) ? implode('|', $rules['title']) : $rules['title'];
        $this->assertStringContainsString('required', $titleRule);
    }

    public function test_update_page_slug_is_required(): void
    {
        $request = new UpdatePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('slug', $rules);
        $slugRule = is_array($rules['slug']) ? implode('|', $rules['slug']) : $rules['slug'];
        $this->assertStringContainsString('required', $slugRule);
    }

    public function test_update_page_content_is_nullable(): void
    {
        $request = new UpdatePageRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('content', $rules);
        $contentRule = is_array($rules['content']) ? implode('|', $rules['content']) : $rules['content'];
        $this->assertStringContainsString('nullable', $contentRule);
    }
}
