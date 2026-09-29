<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SearchHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchHelperTest extends TestCase
{
    public static function likePatternProvider(): array
    {
        return [
            'plain text wraps in percent' => ['foo', '%foo%'],
            'asterisk maps to percent' => ['foo*', 'foo%'],
            'leading asterisk' => ['*bar', '%bar'],
            'middle asterisk' => ['f*o', 'f%o'],
            'multiple asterisks' => ['*f*o*', '%f%o%'],
            'escapes percent' => ['100%', '%100\%%'],
            'escapes underscore' => ['foo_bar', '%foo\_bar%'],
            'empty string' => ['', '%%'],
            'only asterisk' => ['*', '%'],
            'escapes both percent and underscore' => ['100%_foo_bar', '%100\%\_foo\_bar%'],
            'asterisk with special chars' => ['foo_*', 'foo\_%'],
        ];
    }

    #[DataProvider('likePatternProvider')]
    public function test_to_like_pattern(string $input, string $expected): void
    {
        $this->assertEquals($expected, SearchHelper::toLikePattern($input));
    }
}
