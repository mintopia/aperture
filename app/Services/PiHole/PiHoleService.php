<?php

declare(strict_types=1);

namespace App\Services\PiHole;

use App\Models\IpAddress;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PiHoleService implements DnsFilteringInterface
{
    public function __construct(
        protected string $endpoint,
        protected string $password,
        protected int $filteredGroupId,
        protected bool $verifySsl = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            rtrim((string) ($config['endpoint'] ?? ''), '/'),
            (string) ($config['password'] ?? ''),
            (int) ($config['filtered_group_id'] ?? 1),
            (bool) ($config['verify_ssl'] ?? true),
        );
    }

    /**
     * @return array{groups: list<array{id: int, name: string, enabled: bool}>, error?: string}
     */
    public function getGroups(): array
    {
        try {
            if ($this->endpoint === '') {
                return ['groups' => [], 'error' => 'Pi-hole endpoint is not configured.'];
            }

            if ($this->password === '') {
                return ['groups' => [], 'error' => 'Pi-hole password is not configured.'];
            }

            $authResponse = $this->request(10)->post('/api/auth', ['password' => $this->password]);

            if (! $authResponse->successful()) {
                return [
                    'groups' => [],
                    'error' => 'Pi-hole authentication failed (HTTP '.$authResponse->status().'). Check your password.',
                ];
            }

            /** @var string $sid */
            $sid = $authResponse->json('session.sid', '');

            if ($sid === '') {
                return ['groups' => [], 'error' => 'Pi-hole auth succeeded but no session ID found in response.'];
            }

            $response = $this->request(10, $sid)->get('/api/groups');

            if (! $response->successful()) {
                return ['groups' => [], 'error' => 'Failed to fetch Pi-hole groups (HTTP '.$response->status().').'];
            }

            /** @var array<int, array{id: int, name?: string, enabled?: bool}> $groups */
            $groups = $response->json('groups', []);

            return ['groups' => array_values(collect($groups)->map(fn (array $g): array => [
                'id' => $g['id'],
                'name' => $g['name'] ?? 'Group '.$g['id'],
                'enabled' => $g['enabled'] ?? true,
            ])->all())];
        } catch (Throwable $throwable) {
            return ['groups' => [], 'error' => $throwable->getMessage()];
        }
    }

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
            $this->createClient($ipAddress, [$this->filteredGroupId]);

            return;
        }

        if ($client['groups'] === [$this->filteredGroupId]) {
            return;
        }

        $this->updateClientGroups($client['client'], [$this->filteredGroupId], $client['comment']);
    }

    public function disableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return;
        }

        $this->deleteClient($client['client']);
    }

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        $allClients = $this->fetchAllClients();

        $desiredEnabled = IpAddress::where('dns_filtering_enabled', true)
            ->pluck('address')
            ->all();
        $desiredDisabled = IpAddress::where('dns_filtering_enabled', false)
            ->pluck('address')
            ->all();

        /** @var array<int, string> $added */
        $added = [];
        /** @var array<int, string> $removed */
        $removed = [];
        /** @var array<int, string> $unchanged */
        $unchanged = [];
        /** @var array<int, string> $errors */
        $errors = [];

        // Build a map of PiHole clients by IP for fast lookup
        $clientMap = [];
        foreach ($allClients as $c) {
            $clientMap[$c['client']] = $c;
        }

        // Check enabled IPs — should have filteredGroupId
        foreach ($desiredEnabled as $ip) {
            $existing = $clientMap[$ip] ?? null;
            if ($existing !== null && in_array($this->filteredGroupId, $existing['groups'], true)) {
                $unchanged[] = $ip;

                continue;
            }

            $added[] = $ip;
            if (! $dryRun) {
                try {
                    $this->enableForIp($ip);
                } catch (Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        // Check disabled IPs — should have no client in PiHole
        foreach ($desiredDisabled as $ip) {
            $existing = $clientMap[$ip] ?? null;
            if ($existing === null) {
                $unchanged[] = $ip;

                continue;
            }

            $removed[] = $ip;
            if (! $dryRun) {
                try {
                    $this->disableForIp($ip);
                } catch (Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        return new ReconcileResult(
            added: $added,
            removed: $removed,
            unchanged: $unchanged,
            errors: $errors,
        );
    }

    /**
     * @return array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>
     */
    protected function fetchAllClients(): array
    {
        $sid = $this->getSessionId();

        /** @var array<int, array{id: int, client: string, groups: array<int, int>, comment: string}> $clients */
        $clients = $this->request(sid: $sid)->throw()->get('/api/clients')->json('clients');

        return $clients;
    }

    /**
     * @return array{id: int, client: string, groups: array<int, int>, comment: string}|null
     */
    protected function findClient(string $ipAddress): ?array
    {
        $sid = $this->getSessionId();

        /** @var array<int, array{id: int, client: string, groups: array<int, int>, comment: string}> $clients */
        $clients = $this->request(sid: $sid)->throw()->get('/api/clients', ['search' => $ipAddress])->json('clients');

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

        $this->request(sid: $sid)->throw()->post('/api/clients', [
            'client' => $ipAddress,
            'groups' => $groups,
            'comment' => 'Managed by Aperture',
        ]);
    }

    /**
     * @param  array<int, int>  $groups
     */
    protected function updateClientGroups(string $clientIdentifier, array $groups, string $comment = ''): void
    {
        $sid = $this->getSessionId();

        $this->request(sid: $sid)->throw()->put('/api/clients/'.urlencode($clientIdentifier), [
            'comment' => $comment,
            'groups' => $groups,
        ]);
    }

    protected function deleteClient(string $clientIdentifier): void
    {
        $sid = $this->getSessionId();

        $this->request(sid: $sid)->throw()->delete('/api/clients/'.urlencode($clientIdentifier));
    }

    protected function getSessionId(): string
    {
        return Cache::remember('pihole_session_id', 270, function (): string {
            return (string) $this->request()->throw()
                ->post('/api/auth', ['password' => $this->password])
                ->json('session.sid');
        });
    }

    protected function request(?int $timeout = null, ?string $sid = null): PendingRequest
    {
        $request = Http::baseUrl($this->endpoint)
            ->withOptions(['verify' => $this->verifySsl])
            ->asJson();

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        if ($sid !== null) {
            $request->withHeaders(['X-FTL-SID' => $sid]);
        }

        return $request;
    }
}
