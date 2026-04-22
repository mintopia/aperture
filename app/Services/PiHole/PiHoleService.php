<?php

declare(strict_types=1);

namespace App\Services\PiHole;

use App\Models\IpAddress;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class PiHoleService implements DnsFilteringInterface
{
    public function __construct(
        protected Client $client,
        protected string $password,
        protected int $filteredGroupId,
    ) {}

    public function isEnabledForIp(string $ipAddress): bool
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return false;
        }

        return in_array($this->filteredGroupId, $client['groups'], true);
    }

    public function enableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            $this->createClient($ipAddress, [0, $this->filteredGroupId]);

            return;
        }

        if (in_array($this->filteredGroupId, $client['groups'], true)) {
            return;
        }

        $groups = array_merge($client['groups'], [$this->filteredGroupId]);
        $this->updateClientGroups($client['client'], $groups, $client['comment']);
    }

    public function disableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return;
        }

        $groups = array_values(array_filter(
            $client['groups'],
            fn (int $g): bool => $g !== $this->filteredGroupId,
        ));

        if ($groups === $client['groups']) {
            return;
        }

        $this->updateClientGroups($client['client'], $groups, $client['comment']);
    }

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        $allClients = $this->fetchAllClients();

        $enabledIps = IpAddress::where('dns_filtering_enabled', true)->pluck('address')->all();
        $disabledIps = IpAddress::where('dns_filtering_enabled', false)->pluck('address')->all();

        /** @var array<int, string> $added */
        $added = [];
        /** @var array<int, string> $removed */
        $removed = [];
        /** @var array<int, string> $unchanged */
        $unchanged = [];
        /** @var array<int, string> $errors */
        $errors = [];

        foreach ($allClients as $client) {
            $ip = $client['client'];
            $hasFilteredGroup = in_array($this->filteredGroupId, $client['groups'], true);

            if (in_array($ip, $enabledIps, true) && ! $hasFilteredGroup) {
                // Should be enabled but missing filteredGroupId
                if (! $dryRun) {
                    $groups = array_merge($client['groups'], [$this->filteredGroupId]);
                    $this->updateClientGroups($client['client'], $groups, $client['comment']);
                }

                $added[] = $ip;
            } elseif (in_array($ip, $disabledIps, true) && $hasFilteredGroup) {
                // Should be disabled but has filteredGroupId
                if (! $dryRun) {
                    $groups = array_values(array_filter(
                        $client['groups'],
                        fn (int $g): bool => $g !== $this->filteredGroupId,
                    ));
                    $this->updateClientGroups($client['client'], $groups, $client['comment']);
                }

                $removed[] = $ip;
            } else {
                $unchanged[] = $ip;
            }
        }

        return new ReconcileResult($added, $removed, $unchanged, $errors);
    }

    /**
     * @return array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>
     */
    protected function fetchAllClients(): array
    {
        $sid = $this->getSessionId();

        $response = $this->client->get('/api/clients', [
            'headers' => ['X-FTL-SID' => $sid],
        ]);

        /** @var array{clients: array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['clients'];
    }

    /**
     * @return array{id: int, client: string, groups: array<int, int>, comment: string}|null
     */
    protected function findClient(string $ipAddress): ?array
    {
        $sid = $this->getSessionId();

        $response = $this->client->get('/api/clients', [
            'headers' => ['X-FTL-SID' => $sid],
            'query' => ['search' => $ipAddress],
        ]);

        /** @var array{clients: array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);
        $clients = $data['clients'];

        foreach ($clients as $client) {
            if ($client['client'] === $ipAddress) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $groups
     */
    protected function createClient(string $ipAddress, array $groups): void
    {
        $sid = $this->getSessionId();

        $this->client->post('/api/clients', [
            'headers' => ['X-FTL-SID' => $sid],
            'json' => [
                'client' => $ipAddress,
                'groups' => $groups,
                'comment' => 'Managed by Aperture',
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $groups
     */
    protected function updateClientGroups(string $clientIdentifier, array $groups, string $comment = ''): void
    {
        $sid = $this->getSessionId();

        $this->client->put('/api/clients/'.urlencode($clientIdentifier), [
            'headers' => ['X-FTL-SID' => $sid],
            'json' => [
                'comment' => $comment,
                'groups' => $groups,
            ],
        ]);
    }

    protected function getSessionId(): string
    {
        return Cache::remember('pihole_session_id', 270, function (): string {
            $response = $this->client->post('/api/auth', [
                'json' => ['password' => $this->password],
            ]);

            /** @var array{session: array{sid: string, validity: int}} $data */
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['session']['sid'];
        });
    }
}
