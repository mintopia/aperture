<?php

namespace Tests\Unit\Models;

use App\Models\Page;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_can_be_created_with_required_fields(): void
    {
        $page = Page::factory()->create([
            'title' => 'About Us',
            'slug' => 'about-us',
        ]);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'About Us',
            'slug' => 'about-us',
        ]);
    }

    public function test_slug_must_be_unique(): void
    {
        Page::factory()->create(['slug' => 'duplicate-slug']);

        $this->expectException(QueryException::class);

        Page::factory()->create(['slug' => 'duplicate-slug']);
    }

    public function test_content_is_nullable(): void
    {
        $page = Page::factory()->create(['content' => null]);

        $this->assertNull($page->content);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'content' => null]);
    }

    public function test_factory_creates_valid_page(): void
    {
        $page = Page::factory()->create();

        $this->assertDatabaseHas('pages', ['id' => $page->id]);
        $this->assertNotEmpty($page->title);
        $this->assertNotEmpty($page->slug);
    }
}
