<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SafeCss;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeCssTest extends TestCase
{
    private function passes(string $css): bool
    {
        $validator = Validator::make(
            ['css' => $css],
            ['css' => [new SafeCss]],
        );

        return $validator->passes();
    }

    public static function cssValidityProvider(): array
    {
        return [
            'normal css passes' => ['body { color: #333; font-size: 14px; margin: 0 auto; }', true],
            'complex css passes' => ['.container { display: flex; justify-content: center; background-color: rgba(0,0,0,0.5); }', true],
            'css variables pass' => [':root { --color-bg: #fff; } body { color: var(--color-bg); }', true],
            'media queries pass' => ['@media (max-width: 768px) { .col { width: 100%; } }', true],
            'empty string passes' => ['', true],
            'expression is blocked' => ['body { width: expression(document.body.clientWidth); }', false],
            'import is blocked' => ['@import url("https://evil.com/hack.css");', false],
            'url javascript is blocked' => ['body { background: url(javascript:alert(1)); }', false],
            'url data is blocked' => ['body { background: url(data:text/html,<script>alert(1)</script>); }', false],
            'moz-binding is blocked' => ['body { -moz-binding: url("http://evil.com/xbl"); }', false],
            'behavior is blocked' => ['body { behavior: url(xss.htc); }', false],
            'binding is blocked' => ['body { binding(something); }', false],
            'script tag is blocked' => ['body {} <script>alert(1)</script>', false],
            'case-insensitive expression is blocked' => ['body { width: EXPRESSION(document.body.clientWidth); }', false],
            'case-insensitive import is blocked' => ['@IMPORT url("https://evil.com/hack.css");', false],
            'case-insensitive behavior is blocked' => ['body { BEHAVIOR: url(xss.htc); }', false],
        ];
    }

    #[DataProvider('cssValidityProvider')]
    public function test_css_validity(string $css, bool $expectedValid): void
    {
        $this->assertSame($expectedValid, $this->passes($css));
    }

    public function test_error_message(): void
    {
        $validator = Validator::make(
            ['custom_css' => '@import url("evil.css");'],
            ['custom_css' => [new SafeCss]],
        );

        $this->assertFalse($validator->passes());
        $this->assertEquals(
            'The custom css contains potentially dangerous CSS patterns.',
            $validator->errors()->first('custom_css'),
        );
    }

    public function test_null_value_passes(): void
    {
        $validator = Validator::make(
            ['css' => null],
            ['css' => ['nullable', new SafeCss]],
        );

        $this->assertTrue($validator->passes());
    }
}
