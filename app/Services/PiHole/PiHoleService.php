<?php

declare(strict_types=1);

namespace App\Services\PiHole;

use App\Services\Interfaces\DnsBlockingInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class PiHoleService implements DnsBlockingInterface
{
    public function __construct(
        protected Client $client,
        protected string $password,
        protected int $noblockGroupId,
    ) {}

    public function isEnabledForIp(string $ipAddress): bool
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return true;
        }

        return ! in_array($this->noblockGroupId, $client['groups'], true);
    }

    public function enableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return;
        }

        $groups = array_values(array_filter(
            $client['groups'],
            fn (int $g): bool => $g !== $this->noblockGroupId,
        ));

        if ($groups === $client['groups']) {
            return;
        }

        $this->updateClientGroups($client['id'], $groups);
    }

    public function disableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            $this->createClient($ipAddress, [0, $this->noblockGroupId]);

            return;
        }

        if (in_array($this->noblockGroupId, $client['groups'], true)) {
            return;
        }

        $groups = array_merge($client['groups'], [$this->noblockGroupId]);
        $this->updateClientGroups($client['id'], $groups);
    }

    /**
     * @return array{id: int, client: string, groups: array<int, int>, comment: string}|null
     */
    protected function findClient(string $ipAddress): ?array
    {
        $token = $this->getAuthToken();

        $response = $this->client->get('/api/clients', [
            'headers' => ['Authorization' => 'Token '.$token],
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
        $token = $this->getAuthToken();

        $this->client->post('/api/clients', [
            'headers' => ['Authorization' => 'Token '.$token],
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
    protected function updateClientGroups(int $clientId, array $groups): void
    {
        $token = $this->getAuthToken();

        $this->client->put('/api/clients/'.$clientId, [
            'headers' => ['Authorization' => 'Token '.$token],
            'json' => [
                'groups' => $groups,
            ],
        ]);
    }

    protected function getAuthToken(): string
    {
        return Cache::remember('pihole_auth_token', 270, function (): string {
            $response = $this->client->post('/api/auth', [
                'json' => ['password' => $this->password],
            ]);

            /** @var array{session: array{token: string, validity: int}} $data */
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['session']['token'];
        });
    }
}
