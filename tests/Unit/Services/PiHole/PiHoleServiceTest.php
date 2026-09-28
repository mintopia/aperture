<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PiHole;

use Throwable;
use App\Models\IpAddress;
use App\Services\PiHole\PiHoleService;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Fake;
use Tests\TestCase;

class PiHoleServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param list<PromiseInterface|Throwable> $responses
     */
    private function createServiceWithMock(array $responses): PiHoleService
    {
        Fake::sequence($responses);

        return new PiHoleService('http://pihole.test', 'test-password', 1);
    }

    private function authResponse(): PromiseInterface
    {
        return Fake::response(200, [], (string) json_encode([
            'session' => [
                'sid' => 'test-session-id',
                'validity' => 300,
            ],
        ]));
    }

    public function test_is_enabled_returns_true_when_filtered_group_in_client_groups(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1], 'comment' => ''],
                ],
            ])),
        ]);

        $this->assertTrue($service->isEnabledForIp('10.0.0.10'));
    }

    public function test_is_enabled_returns_false_when_filtered_group_not_in_client_groups(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => ''],
                ],
            ])),
        ]);

        $this->assertFalse($service->isEnabledForIp('10.0.0.10'));
    }

    public function test_is_enabled_returns_false_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
        ]);

        $this->assertFalse($service->isEnabledForIp('10.0.0.99'));
    }

    public function test_enable_sets_only_filtered_group_on_existing_client(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => 'Test comment'],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = Fake::requests()[2];
        $this->assertSame('PUT', $putRequest->method());
        $this->assertSame('/api/clients/10.0.0.10', parse_url($putRequest->url(), PHP_URL_PATH));

        $body = $putRequest->data();
        $this->assertSame([1], $body['groups']);
        $this->assertSame('Test comment', $body['comment']);
    }

    public function test_enable_creates_client_with_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            Fake::response(201, [], (string) json_encode(['client' => ['id' => 10]])),
        ]);

        $service->enableForIp('10.0.0.20');

        $postRequest = Fake::requests()[2];
        $this->assertSame('POST', $postRequest->method());
        $this->assertSame('/api/clients', parse_url($postRequest->url(), PHP_URL_PATH));

        $body = $postRequest->data();
        $this->assertSame('10.0.0.20', $body['client']);
        $this->assertSame([1], $body['groups']);
    }

    public function test_enable_is_idempotent_when_already_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [1], 'comment' => ''],
                ],
            ])),
        ]);

        $service->enableForIp('10.0.0.10');

        // Only auth + GET, no PUT
        $this->assertCount(2, Fake::requests());
    }

    public function test_enable_replaces_multiple_groups_with_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1, 2], 'comment' => ''],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = Fake::requests()[2];
        $body = $putRequest->data();
        $this->assertSame([1], $body['groups']);
    }

    public function test_disable_deletes_client_from_pihole(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            Fake::response(204),
        ]);

        $service->disableForIp('10.0.0.10');

        $deleteRequest = Fake::requests()[2];
        $this->assertSame('DELETE', $deleteRequest->method());
        $this->assertSame('/api/clients/10.0.0.10', parse_url($deleteRequest->url(), PHP_URL_PATH));
    }

    public function test_disable_is_noop_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
        ]);

        $service->disableForIp('10.0.0.99');

        // Only auth + GET, no PUT or POST
        $this->assertCount(2, Fake::requests());
    }

    public function test_session_id_is_cached(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            Fake::response(200, [], (string) json_encode(['clients' => []])),
            Fake::response(200, [], (string) json_encode(['clients' => []])),
        ]);

        $service->isEnabledForIp('10.0.0.10');
        $service->isEnabledForIp('10.0.0.11');

        // Auth called once (cached), then 2 GET requests = 3 total
        $this->assertCount(3, Fake::requests());
        $this->assertSame('POST', Fake::requests()[0]->method()); // auth
        $this->assertSame('GET', Fake::requests()[1]->method());
        $this->assertSame('GET', Fake::requests()[2]->method());
    }

    public function test_reconcile_enables_filtering_for_ips_missing_it(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response — 10.0.0.1 exists but wrong groups
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // enableForIp('10.0.0.1') → findClient GET
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // enableForIp('10.0.0.1') → updateClientGroups PUT
            Fake::response(200, [], (string) json_encode(['client' => ['id' => 1]])),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['10.0.0.2'], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_deletes_clients_for_disabled_ips(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // disableForIp('10.0.0.2') → findClient GET
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // disableForIp('10.0.0.2') → deleteClient DELETE
            Fake::response(204),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame(['10.0.0.2'], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);

        $deleteRequest = Fake::requests()[3];
        $this->assertSame('DELETE', $deleteRequest->method());
        $this->assertSame('/api/clients/10.0.0.2', parse_url($deleteRequest->url(), PHP_URL_PATH));
    }

    public function test_reconcile_creates_client_for_enabled_ip_not_in_pihole(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.5', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response — 10.0.0.5 not present
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.5') → findClient GET returns empty
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.5') → createClient POST
            Fake::response(201, [], (string) json_encode(['client' => ['id' => 10]])),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.5'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);

        $postRequest = Fake::requests()[3];
        $this->assertSame('POST', $postRequest->method());
        $this->assertSame('/api/clients', parse_url($postRequest->url(), PHP_URL_PATH));

        $body = $postRequest->data();
        $this->assertSame('10.0.0.5', $body['client']);
        $this->assertSame([1], $body['groups']);
    }

    public function test_reconcile_dry_run_does_not_apply_changes(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => 'Managed by Aperture'],
                ],
            ])),
        ]);

        $result = $service->reconcile(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame([], $result->removed);

        // Only auth + GET for fetchAllClients, no further API calls
        $this->assertCount(2, Fake::requests());
    }

    public function test_reconcile_handles_mixed_changes(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);
        IpAddress::factory()->create(['address' => '10.0.0.3', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.4', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => ''],      // needs fix (wrong groups)
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => ''],       // needs delete (disabled)
                    ['id' => 3, 'client' => '10.0.0.3', 'groups' => [1], 'comment' => ''],       // unchanged (correct)
                    // 10.0.0.4 not in PiHole — unchanged (correct, disabled)
                ],
            ])),
            // enableForIp('10.0.0.1') → findClient GET
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => ''],
                ],
            ])),
            // enableForIp('10.0.0.1') → updateClientGroups PUT
            Fake::response(200, [], (string) json_encode(['client' => ['id' => 1]])),
            // disableForIp('10.0.0.2') → findClient GET
            Fake::response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => ''],
                ],
            ])),
            // disableForIp('10.0.0.2') → deleteClient DELETE
            Fake::response(204),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame(['10.0.0.2'], $result->removed);
        $this->assertEqualsCanonicalizing(['10.0.0.3', '10.0.0.4'], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_with_no_clients_in_pihole_creates_enabled_ips(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response - empty
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → findClient GET returns empty
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → createClient POST
            Fake::response(201, [], (string) json_encode(['client' => ['id' => 1]])),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        // 10.0.0.1 is enabled in DB but absent from PiHole — must be created
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_captures_errors_without_aborting(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients — neither IP has a client
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → findClient GET — returns a server error
            Fake::response(500, [], (string) json_encode(['error' => 'server error'])),
            // enableForIp('10.0.0.2') → findClient GET — succeeds (empty)
            Fake::response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.2') → createClient POST
            Fake::response(201, [], (string) json_encode(['client' => ['id' => 2]])),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertCount(1, $result->errors);
        $this->assertStringStartsWith('10.0.0.1:', $result->errors[0]);
    }
}
