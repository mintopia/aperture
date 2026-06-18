<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SearchHelper;
use Tests\TestCase;

class SearchHelperTest extends TestCase
{
    public function test_plain_text_wraps_in_percent(): void
    {
        $this->assertEquals('%foo%', SearchHelper::toLikePattern('foo'));
    }

    public function test_asterisk_maps_to_percent(): void
    {
        $this->assertEquals('foo%', SearchHelper::toLikePattern('foo*'));
    }

    public function test_leading_asterisk(): void
    {
        $this->assertEquals('%bar', SearchHelper::toLikePattern('*bar'));
    }

    public function test_middle_asterisk(): void
    {
        $this->assertEquals('f%o', SearchHelper::toLikePattern('f*o'));
    }

    public function test_multiple_asterisks(): void
    {
        $this->assertEquals('%f%o%', SearchHelper::toLikePattern('*f*o*'));
    }

    public function test_escapes_percent(): void
    {
        $this->assertEquals('%100\%%', SearchHelper::toLikePattern('100%'));
    }

    public function test_escapes_underscore(): void
    {
        $this->assertEquals('%foo\_bar%', SearchHelper::toLikePattern('foo_bar'));
    }

    public function test_empty_string(): void
    {
        $this->assertEquals('%%', SearchHelper::toLikePattern(''));
    }

    public function test_only_asterisk(): void
    {
        $this->assertEquals('%', SearchHelper::toLikePattern('*'));
    }

    public function test_escapes_both_percent_and_underscore(): void
    {
        $this->assertEquals('%100\%\_foo\_bar%', SearchHelper::toLikePattern('100%_foo_bar'));
    }

    public function test_asterisk_with_special_chars(): void
    {
        $this->assertEquals('foo\_%', SearchHelper::toLikePattern('foo_*'));
    }
}
