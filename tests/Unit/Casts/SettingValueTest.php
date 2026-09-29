<?php

namespace Tests\Unit\Casts;

use App\Casts\SettingValue;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Tests\TestCase;

class SettingValueTest extends TestCase
{
    public function test_get_decodes_json_and_returns_value(): void
    {
        $cast = new SettingValue;
        $model = Mockery::mock(Model::class);
        $result = $cast->get($model, 'value', '{"value":"hello"}', []);
        $this->assertEquals('hello', $result);
    }

    public function test_get_handles_boolean_value(): void
    {
        $cast = new SettingValue;
        $model = Mockery::mock(Model::class);
        $result = $cast->get($model, 'value', '{"value":true}', []);
        $this->assertTrue($result);
    }

    public function test_get_handles_numeric_value(): void
    {
        $cast = new SettingValue;
        $model = Mockery::mock(Model::class);
        $result = $cast->get($model, 'value', '{"value":42}', []);
        $this->assertEquals(42, $result);
    }

    public function test_set_encodes_value_as_json(): void
    {
        $cast = new SettingValue;
        $model = Mockery::mock(Model::class);
        $result = $cast->set($model, 'value', 'hello', []);
        $decoded = json_decode($result);
        $this->assertEquals('hello', $decoded->value);
    }

    public function test_set_handles_boolean_value(): void
    {
        $cast = new SettingValue;
        $model = Mockery::mock(Model::class);
        $result = $cast->set($model, 'value', true, []);
        $decoded = json_decode($result);
        $this->assertTrue($decoded->value);
    }
}
