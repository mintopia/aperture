<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    public function test_set_creates_new_setting_when_none_exists(): void
    {
        Setting::set('new.setting', 'New Setting', 'new_value');

        $setting = Setting::whereCode('new.setting')->first();
        $this->assertNotNull($setting);
        $this->assertEquals('new.setting', $setting->code);
        $this->assertEquals('New Setting', $setting->name);
        $this->assertEquals('new_value', $setting->value);
    }

    public function test_set_updates_existing_setting_value(): void
    {
        $existing = new Setting;
        $existing->code = 'existing.setting';
        $existing->name = 'Existing Setting';
        $existing->value = 'old_value';
        $existing->save();

        Setting::set('existing.setting', 'Existing Setting', 'updated_value');

        $this->assertEquals(1, Setting::whereCode('existing.setting')->count());
        $this->assertEquals('updated_value', Setting::get('existing.setting'));
    }

    public function test_set_handles_null_value(): void
    {
        Setting::set('test.code', 'Name', null);

        $setting = Setting::whereCode('test.code')->first();
        $this->assertNotNull($setting);
        $this->assertNull($setting->value);
        $this->assertNull(Setting::get('test.code'));
    }
}
