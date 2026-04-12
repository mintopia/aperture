<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\NormalizeMacAddress;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class NormalizeMacAddressTest extends TestCase
{
    private NormalizeMacAddress $cast;

    private MockObject $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new NormalizeMacAddress;
        $this->model = $this->createMock(Model::class);
    }

    public function test_set_normalizes_colon_lowercase(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'aa:bb:cc:dd:ee:ff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_normalizes_colon_uppercase(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'AA:BB:CC:DD:EE:FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_normalizes_dash_separated(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'AA-BB-CC-DD-EE-FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_normalizes_cisco_format(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'aabb.ccdd.eeff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_normalizes_bare_hex(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'aabbccddeeff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_normalizes_mixed_case(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', 'Aa:bB:Cc:dD:Ee:fF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_handles_null(): void
    {
        $result = $this->cast->set($this->model, 'mac_address', null, []);
        $this->assertNull($result);
    }

    public function test_get_returns_value_as_is(): void
    {
        $result = $this->cast->get($this->model, 'mac_address', 'AA:BB:CC:DD:EE:FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_get_handles_null(): void
    {
        $result = $this->cast->get($this->model, 'mac_address', null, []);
        $this->assertNull($result);
    }
}
