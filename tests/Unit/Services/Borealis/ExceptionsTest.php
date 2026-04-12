<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Borealis;

use App\Services\Borealis\BorealisException;
use App\Services\Borealis\DeviceCodeStatus;
use App\Services\Borealis\RequestException;
use App\Services\Firewalls\Exceptions\BackendException;
use Exception;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function test_borealis_exception_is_exception(): void
    {
        $e = new BorealisException('test');
        $this->assertInstanceOf(Exception::class, $e);
        $this->assertEquals('test', $e->getMessage());
    }

    public function test_request_exception_extends_borealis_exception(): void
    {
        $e = new RequestException('request error');
        $this->assertInstanceOf(BorealisException::class, $e);
        $this->assertEquals('request error', $e->getMessage());
    }

    public function test_backend_exception_is_exception(): void
    {
        $e = new BackendException('backend error');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function test_device_code_status_enum(): void
    {
        $this->assertEquals('dcsPending', DeviceCodeStatus::dcsPending->name);
        $this->assertEquals('dcsFailed', DeviceCodeStatus::dcsFailed->name);
        $this->assertEquals('dcsSuccessful', DeviceCodeStatus::dcsSuccessful->name);
    }
}
