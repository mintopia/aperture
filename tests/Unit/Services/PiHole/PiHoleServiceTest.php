<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PiHole;

use App\Models\IpAddress;
use App\Services\PiHole\PiHoleService;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class PiHoleServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<int, array{request: Request}> */
    private array $history = [];

    /**
     * @param  array<int, Response>  $responses
     */
    private function createServiceWithMock(array $responses): PiHoleService
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $handler->push($this->captureHistory());

        $client = new Client(['handler' => $handler]);

        return new PiHoleService($client, 'test-password', 1);
    }

    private function captureHistory(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request): ResponseInterface {
                        /** @var Request $request */
                        $this->history[] = ['request' => $request];

                        return $response;
                    }
                );
            };
        };
    }

    private function authResponse(): Response
    {
        return new Response(200, [], (string) json_encode([
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
            new Response(200, [], (string) json_encode([
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
            new Response(200, [], (string) json_encode([
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
            new Response(200, [], (string) json_encode([
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
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => 'Test comment'],
                ],
            ])),
            new Response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = $this->history[2]['request'];
        $this->assertSame('PUT', $putRequest->getMethod());
        $this->assertSame('/api/clients/10.0.0.10', $putRequest->getUri()->getPath());

        $body = json_decode($putRequest->getBody()->getContents(), true);
        $this->assertSame([1], $body['groups']);
        $this->assertSame('Test comment', $body['comment']);
    }

    public function test_enable_creates_client_with_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            new Response(201, [], (string) json_encode(['client' => ['id' => 10]])),
        ]);

        $service->enableForIp('10.0.0.20');

        $postRequest = $this->history[2]['request'];
        $this->assertSame('POST', $postRequest->getMethod());
        $this->assertSame('/api/clients', $postRequest->getUri()->getPath());

        $body = json_decode($postRequest->getBody()->getContents(), true);
        $this->assertSame('10.0.0.20', $body['client']);
        $this->assertSame([1], $body['groups']);
    }

    public function test_enable_is_idempotent_when_already_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [1], 'comment' => ''],
                ],
            ])),
        ]);

        $service->enableForIp('10.0.0.10');

        // Only auth + GET, no PUT
        $this->assertCount(2, $this->history);
    }

    public function test_enable_replaces_multiple_groups_with_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1, 2], 'comment' => ''],
                ],
            ])),
            new Response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = $this->history[2]['request'];
        $body = json_decode($putRequest->getBody()->getContents(), true);
        $this->assertSame([1], $body['groups']);
    }

    public function test_disable_deletes_client_from_pihole(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            new Response(204),
        ]);

        $service->disableForIp('10.0.0.10');

        $deleteRequest = $this->history[2]['request'];
        $this->assertSame('DELETE', $deleteRequest->getMethod());
        $this->assertSame('/api/clients/10.0.0.10', $deleteRequest->getUri()->getPath());
    }

    public function test_disable_is_noop_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
        ]);

        $service->disableForIp('10.0.0.99');

        // Only auth + GET, no PUT or POST
        $this->assertCount(2, $this->history);
    }

    public function test_session_id_is_cached(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode(['clients' => []])),
            new Response(200, [], (string) json_encode(['clients' => []])),
        ]);

        $service->isEnabledForIp('10.0.0.10');
        $service->isEnabledForIp('10.0.0.11');

        // Auth called once (cached), then 2 GET requests = 3 total
        $this->assertCount(3, $this->history);
        $this->assertSame('POST', $this->history[0]['request']->getMethod()); // auth
        $this->assertSame('GET', $this->history[1]['request']->getMethod());
        $this->assertSame('GET', $this->history[2]['request']->getMethod());
    }

    public function test_reconcile_enables_filtering_for_ips_missing_it(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response — 10.0.0.1 exists but wrong groups
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // enableForIp('10.0.0.1') → findClient GET
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // enableForIp('10.0.0.1') → updateClientGroups PUT
            new Response(200, [], (string) json_encode(['client' => ['id' => 1]])),
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
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // disableForIp('10.0.0.2') → findClient GET
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => 'Managed by Aperture'],
                ],
            ])),
            // disableForIp('10.0.0.2') → deleteClient DELETE
            new Response(204),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame(['10.0.0.2'], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);

        $deleteRequest = $this->history[3]['request'];
        $this->assertSame('DELETE', $deleteRequest->getMethod());
        $this->assertSame('/api/clients/10.0.0.2', $deleteRequest->getUri()->getPath());
    }

    public function test_reconcile_creates_client_for_enabled_ip_not_in_pihole(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.5', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            // fetchAllClients response — 10.0.0.5 not present
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.5') → findClient GET returns empty
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.5') → createClient POST
            new Response(201, [], (string) json_encode(['client' => ['id' => 10]])),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.5'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);

        $postRequest = $this->history[3]['request'];
        $this->assertSame('POST', $postRequest->getMethod());
        $this->assertSame('/api/clients', $postRequest->getUri()->getPath());

        $body = json_decode($postRequest->getBody()->getContents(), true);
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
            new Response(200, [], (string) json_encode([
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
        $this->assertCount(2, $this->history);
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
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => ''],      // needs fix (wrong groups)
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => ''],       // needs delete (disabled)
                    ['id' => 3, 'client' => '10.0.0.3', 'groups' => [1], 'comment' => ''],       // unchanged (correct)
                    // 10.0.0.4 not in PiHole — unchanged (correct, disabled)
                ],
            ])),
            // enableForIp('10.0.0.1') → findClient GET
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 1, 'client' => '10.0.0.1', 'groups' => [0], 'comment' => ''],
                ],
            ])),
            // enableForIp('10.0.0.1') → updateClientGroups PUT
            new Response(200, [], (string) json_encode(['client' => ['id' => 1]])),
            // disableForIp('10.0.0.2') → findClient GET
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 2, 'client' => '10.0.0.2', 'groups' => [1], 'comment' => ''],
                ],
            ])),
            // disableForIp('10.0.0.2') → deleteClient DELETE
            new Response(204),
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
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → findClient GET returns empty
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → createClient POST
            new Response(201, [], (string) json_encode(['client' => ['id' => 1]])),
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
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.1') → findClient GET — returns a server error
            new Response(500, [], (string) json_encode(['error' => 'server error'])),
            // enableForIp('10.0.0.2') → findClient GET — succeeds (empty)
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            // enableForIp('10.0.0.2') → createClient POST
            new Response(201, [], (string) json_encode(['client' => ['id' => 2]])),
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
