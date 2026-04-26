<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ConnectionTestLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConnectionTestLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_record_successful_test(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record('opnsense', true, 'Connection successful');

        $this->assertSame('opnsense', $log->integration);
        $this->assertTrue($log->success);
        $this->assertSame('Connection successful', $log->message);
        $this->assertNull($log->response_time_ms);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
            'integration' => 'opnsense',
            'success' => true,
            'message' => 'Connection successful',
        ]);
    }

    public function test_can_record_failed_test(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record('librenms', false, 'Connection failed: unauthorized');

        $this->assertSame('librenms', $log->integration);
        $this->assertFalse($log->success);
        $this->assertSame('Connection failed: unauthorized', $log->message);
        $this->assertNull($log->response_time_ms);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
            'integration' => 'librenms',
            'success' => false,
            'message' => 'Connection failed: unauthorized',
        ]);
    }

    public function test_record_with_response_time(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record('pihole', true, 'Connection successful', 245);

        $this->assertSame(245, $log->response_time_ms);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
            'response_time_ms' => 245,
        ]);
    }

    public function test_record_with_response_data(): void
    {
        Queue::fake();

        $responseData = '{"status":"ok","version":"1.0"}';
        $log = ConnectionTestLog::record('opnsense', true, 'Connection successful', null, $responseData);

        $this->assertSame($responseData, $log->response_data);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
            'response_data' => $responseData,
        ]);
    }

    public function test_record_without_response_data(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record('opnsense', true, 'Connection successful');

        $this->assertNull($log->response_data);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
        ]);
    }

    public function test_record_with_request_response_detail(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record(
            'opnsense',
            true,
            'Connected successfully',
            null,
            '{"status":"ok"}',
            'GET',
            'https://opnsense.local/api/diagnostics/system/system_time',
            200,
        );

        $this->assertSame('GET', $log->request_method);
        $this->assertSame('https://opnsense.local/api/diagnostics/system/system_time', $log->request_url);
        $this->assertSame(200, $log->response_status);
        $this->assertDatabaseHas('connection_test_logs', [
            'id' => $log->id,
            'request_method' => 'GET',
            'request_url' => 'https://opnsense.local/api/diagnostics/system/system_time',
            'response_status' => 200,
        ]);
    }

    public function test_record_without_request_response_detail(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::record('opnsense', true, 'Connection successful');

        $this->assertNull($log->request_method);
        $this->assertNull($log->request_url);
        $this->assertNull($log->response_status);
    }

    public function test_latest_for_returns_most_recent(): void
    {
        Queue::fake();

        ConnectionTestLog::factory()->create([
            'integration' => 'opnsense',
            'message' => 'Older test',
            'created_at' => now()->subMinute(),
        ]);
        $latest = ConnectionTestLog::factory()->create([
            'integration' => 'opnsense',
            'message' => 'Newest test',
            'created_at' => now(),
        ]);

        $result = ConnectionTestLog::latestFor('opnsense');

        $this->assertNotNull($result);
        $this->assertTrue($latest->is($result));
        $this->assertSame('Newest test', $result->message);
    }

    public function test_latest_for_returns_null_when_none(): void
    {
        Queue::fake();

        $result = ConnectionTestLog::latestFor('missing');

        $this->assertNull($result);
    }

    public function test_recent_for_returns_limited_results(): void
    {
        Queue::fake();

        foreach (range(1, 5) as $index) {
            ConnectionTestLog::factory()->create([
                'integration' => 'ntopng',
                'message' => 'Test '.$index,
                'created_at' => now()->addSeconds($index),
            ]);
        }

        $results = ConnectionTestLog::recentFor('ntopng', 3);

        $this->assertCount(3, $results);
        $this->assertSame(['Test 5', 'Test 4', 'Test 3'], $results->pluck('message')->all());
    }

    public function test_recent_for_ordered_by_newest_first(): void
    {
        Queue::fake();

        ConnectionTestLog::factory()->create([
            'integration' => 'librenms',
            'message' => 'Older test',
            'created_at' => now()->subMinute(),
        ]);
        ConnectionTestLog::factory()->create([
            'integration' => 'librenms',
            'message' => 'Newer test',
            'created_at' => now(),
        ]);

        $results = ConnectionTestLog::recentFor('librenms');

        $this->assertSame(['Newer test', 'Older test'], $results->pluck('message')->all());
    }

    public function test_factory_creates_valid_model(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::factory()->create();

        $this->assertInstanceOf(ConnectionTestLog::class, $log);
        $this->assertContains($log->integration, ['opnsense', 'librenms', 'pihole', 'prometheus', 'borealis']);
        $this->assertIsBool($log->success);
        $this->assertNotNull($log->message);
        $this->assertNotNull($log->id);
    }

    public function test_factory_successful_state(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::factory()->successful()->create();

        $this->assertTrue($log->success);
        $this->assertSame('Connection successful', $log->message);
    }

    public function test_factory_failed_state(): void
    {
        Queue::fake();

        $log = ConnectionTestLog::factory()->failed()->create();

        $this->assertFalse($log->success);
        $this->assertSame('Connection failed: timeout', $log->message);
    }
}
