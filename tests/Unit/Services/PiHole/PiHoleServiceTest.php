<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PiHole;

use App\Models\IpAddress;
use App\Services\PiHole\PiHoleService;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PiHoleServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<int, PromiseInterface>  $responses
     */
    private function createServiceWithMock(array $responses): PiHoleService
    {
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->pushResponse($response);
        }

        Http::fake(['*' => $sequence]);

        return new PiHoleService('http://pihole.local', 'test-password', 1);
    }

    /**
     * @return array<int, Request>
     */
    private function history(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    private function piholeAuthOk(): PromiseInterface
    {
        return Http::response([
            'session' => [
                'sid' => 'test-session-id',
                'validity' => 300,
            ],
        ]);
    }

    /**
     * @return array{id: int, client: string, groups: array<int, int>, comment: string}
     */
    private function piholeClient(int $id, string $ip, array $groups, string $comment): array
    {
        return ['id' => $id, 'client' => $ip, 'groups' => $groups, 'comment' => $comment];
    }

    /**
     * @param  array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>  $clients
     */
    private function piholeList(array $clients): PromiseInterface
    {
        return Http::response(['clients' => $clients], 200);
    }

    private function piholeListEmpty(): PromiseInterface
    {
        return $this->piholeList([]);
    }

    private function piholeClientSaved(int $id): PromiseInterface
    {
        return Http::response(['client' => ['id' => $id]], 200);
    }

    private function piholeClientCreated(int $id): PromiseInterface
    {
        return Http::response(['client' => ['id' => $id]], 201);
    }

    private function piholeClientDeleted(): PromiseInterface
    {
        return Http::response('', 204);
    }

    private function piholeServerError(): PromiseInterface
    {
        return Http::response(['error' => 'server error'], 500);
    }

    public function test_enable_sets_only_filtered_group_on_existing_client(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(5, '10.0.0.10', [0], 'Test comment'),
            ]),
            $this->piholeClientSaved(5),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = $this->history()[2];
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
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
            $this->piholeClientCreated(10),
        ]);

        $service->enableForIp('10.0.0.20');

        $postRequest = $this->history()[2];
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
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(5, '10.0.0.10', [1], ''),
            ]),
        ]);

        $service->enableForIp('10.0.0.10');

        Http::assertSentCount(2);
    }

    public function test_enable_replaces_multiple_groups_with_only_filtered_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(5, '10.0.0.10', [0, 1, 2], ''),
            ]),
            $this->piholeClientSaved(5),
        ]);

        $service->enableForIp('10.0.0.10');

        $putRequest = $this->history()[2];
        $body = $putRequest->data();
        $this->assertSame([1], $body['groups']);
    }

    public function test_disable_deletes_client_from_pihole(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(5, '10.0.0.10', [1], 'Managed by Aperture'),
            ]),
            $this->piholeClientDeleted(),
        ]);

        $service->disableForIp('10.0.0.10');

        $deleteRequest = $this->history()[2];
        $this->assertSame('DELETE', $deleteRequest->method());
        $this->assertSame('/api/clients/10.0.0.10', parse_url($deleteRequest->url(), PHP_URL_PATH));
    }

    public function test_disable_is_noop_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
        ]);

        $service->disableForIp('10.0.0.99');

        Http::assertSentCount(2);
    }

    public function test_session_id_is_cached(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
            $this->piholeListEmpty(),
        ]);

        $service->disableForIp('10.0.0.10');
        $service->disableForIp('10.0.0.11');

        Http::assertSentCount(3);
        $this->assertSame('POST', $this->history()[0]->method());
        $this->assertSame('GET', $this->history()[1]->method());
        $this->assertSame('GET', $this->history()[2]->method());
    }

    public function test_reconcile_enables_filtering_for_ips_missing_it(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [0], 'Managed by Aperture'),
            ]),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [0], 'Managed by Aperture'),
            ]),
            $this->piholeClientSaved(1),
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
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [1], 'Managed by Aperture'),
                $this->piholeClient(2, '10.0.0.2', [1], 'Managed by Aperture'),
            ]),
            $this->piholeList([
                $this->piholeClient(2, '10.0.0.2', [1], 'Managed by Aperture'),
            ]),
            $this->piholeClientDeleted(),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame(['10.0.0.2'], $result->removed);
        $this->assertSame(['10.0.0.1'], $result->unchanged);
        $this->assertSame([], $result->errors);

        $deleteRequest = $this->history()[3];
        $this->assertSame('DELETE', $deleteRequest->method());
        $this->assertSame('/api/clients/10.0.0.2', parse_url($deleteRequest->url(), PHP_URL_PATH));
    }

    public function test_reconcile_creates_client_for_enabled_ip_not_in_pihole(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.5', 'dns_filtering_enabled' => true]);

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
            $this->piholeListEmpty(),
            $this->piholeClientCreated(10),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.5'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);

        $postRequest = $this->history()[3];
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
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [0], 'Managed by Aperture'),
            ]),
        ]);

        $result = $service->reconcile(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.1'], $result->added);
        $this->assertSame([], $result->removed);

        Http::assertSentCount(2);
    }

    public function test_reconcile_handles_mixed_changes(): void
    {
        Cache::flush();

        IpAddress::factory()->create(['address' => '10.0.0.1', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.2', 'dns_filtering_enabled' => false]);
        IpAddress::factory()->create(['address' => '10.0.0.3', 'dns_filtering_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.4', 'dns_filtering_enabled' => false]);

        $service = $this->createServiceWithMock([
            $this->piholeAuthOk(),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [0], ''),
                $this->piholeClient(2, '10.0.0.2', [1], ''),
                $this->piholeClient(3, '10.0.0.3', [1], ''),
            ]),
            $this->piholeList([
                $this->piholeClient(1, '10.0.0.1', [0], ''),
            ]),
            $this->piholeClientSaved(1),
            $this->piholeList([
                $this->piholeClient(2, '10.0.0.2', [1], ''),
            ]),
            $this->piholeClientDeleted(),
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
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
            $this->piholeListEmpty(),
            $this->piholeClientCreated(1),
        ]);

        $result = $service->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
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
            $this->piholeAuthOk(),
            $this->piholeListEmpty(),
            $this->piholeServerError(),
            $this->piholeListEmpty(),
            $this->piholeClientCreated(2),
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
