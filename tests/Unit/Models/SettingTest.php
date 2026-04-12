<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_value_when_setting_exists(): void
    {
        $setting = new Setting;
        $setting->code = 'test_setting';
        $setting->name = 'Test Setting';
        $setting->value = 'test_value';
        $setting->save();

        $this->assertEquals('test_value', Setting::get('test_setting'));
    }

    public function test_get_returns_default_when_setting_missing(): void
    {
        $this->assertEquals('default', Setting::get('nonexistent', 'default'));
    }

    public function test_get_returns_null_when_no_default(): void
    {
        $this->assertNull(Setting::get('nonexistent'));
    }

    public function test_to_string_format(): void
    {
        $setting = new Setting;
        $setting->id = 1;
        $setting->code = 'theme';
        $setting->name = 'Theme';

        $str = (string) $setting;
        $this->assertStringContainsString('Setting', $str);
        $this->assertStringContainsString('1', $str);
    }
}
