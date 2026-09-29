<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonServiceProviderTest extends TestCase
{
    public function test_view_horizon_gate_is_defined(): void
    {
        $this->assertTrue(Gate::has('viewHorizon'));
    }

    public function test_view_horizon_gate_denies_null_user(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }
}
