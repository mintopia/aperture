<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\DeviceCodeResource;
use App\Services\Borealis\DeviceCode;
use App\Services\Borealis\DeviceCodeStatus;
use App\Services\BorealisService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class DeviceCodeResourceTest extends TestCase
{
    public function test_to_array_returns_expected_structure(): void
    {
        $service = Mockery::mock(BorealisService::class);
        $code = new DeviceCode($service, 'test');
        $code->expiresAt = CarbonImmutable::parse('2025-01-01T00:00:00Z');
        $code->status = DeviceCodeStatus::dcsPending;
        $code->interval = 5;
        $code->userCode = 'ABCD-EFGH';

        $resource = new DeviceCodeResource($code);
        $request = Request::create('/test');
        $array = $resource->toArray($request);

        $this->assertArrayHasKey('expires_at', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertEquals('dcsPending', $array['status']);
        $this->assertEquals(5, $array['interval']);
        $this->assertEquals('ABCD-EFGH', $array['code']);
    }

    public function test_to_array_handles_successful_status(): void
    {
        $service = Mockery::mock(BorealisService::class);
        $code = new DeviceCode($service, 'test');
        $code->expiresAt = CarbonImmutable::now()->addHour();
        $code->status = DeviceCodeStatus::dcsSuccessful;
        $code->interval = 5;
        $code->userCode = 'TEST-CODE';

        $resource = new DeviceCodeResource($code);
        $request = Request::create('/test');
        $array = $resource->toArray($request);

        $this->assertEquals('dcsSuccessful', $array['status']);
    }
}
