<?php

namespace App\Services\Firewalls;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\FirewallBackendInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class OpnSense implements FirewallBackendInterface
{
    protected int $zoneId;
    protected string $aliasUuid;
    protected Client $client;

    /**
     * @param string $uri
     * @param array<string, mixed> $query
     * @return object
     * @throws BackendException
     */
    protected function get(string $uri, array $query = []): object
    {
        $options = $this->makeOptions($query);
        try {
            Log::debug("[OpnSense] GET {$uri}");
            $response = $this->client->get($uri, $options);
            return $this->decodeResponse($response);
        } catch (GuzzleException $ex) {
            throw new BackendException("Error from Opnsense: {$ex->getMessage()}", $ex->getCode(), $ex);
        }
    }

    /**
     * @param string $uri
     * @param array<string, mixed> $query
     * @param array<string, mixed>|\stdClass|null $payload
     * @return object
     * @throws BackendException
     */
    protected function post(string $uri, array $query = [], array|\stdClass|null $payload = []): object
    {
        $options = $this->makeOptions($query, $payload);
        try {
            Log::debug("[OpnSense] POST {$uri}");
            $response = $this->client->post($uri, $options);
            return $this->decodeResponse($response);
        } catch (GuzzleException $ex) {
            throw new BackendException("Error from Opnsense: {$ex->getMessage()}", $ex->getCode(), $ex);
        }
    }

    /**
     * @param ResponseInterface $response
     * @return object
     * @throws BackendException
     */
    protected function decodeResponse(ResponseInterface $response): object
    {
        $json = json_decode($response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackendException("Unable to decode response");
        }
        return (object) $json;
    }


    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|\stdClass|null $payload
     * @return array<string, mixed>
     */
    protected function makeOptions(array $query = [], array|\stdClass|null $payload = null): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        return $options;
    }

    /**
     * @param Client $client
     * @param int $zoneId
     */
    public function __construct()
    {
        $this->zoneId = config('aperture.opnsense.zoneid');
        $this->aliasUuid = config('aperture.opnsense.ratelimituuid');

        $this->client = new Client([
            'verify' => config('aperture.opnsense.verify'),
            'base_uri' => config('aperture.opnsense.endpoint'),
            'auth' => [
                config('aperture.opnsense.key'),
                config('aperture.opnsense.secret'),
            ],
        ]);
    }

    /**
     * @param string $ip
     * @param string $description
     * @return $this
     * @throws BackendException
     */
    public function updateIp(string $ip, string $description): self
    {
        $payload = (object)[
            'user' => $description,
            'ip' => $ip,
        ];
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $this->post('/api/captiveportal/session/connect', $query, $payload);
        return $this;
    }

    /**
     * @param string $ip
     * @return $this
     * @throws BackendException
     */
    public function removeIp(string $ip): self
    {
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $response = $this->get('/api/captiveportal/session/list', $query);
        if (!is_object($response)) {
            throw new BackendException("Response is malformed");
        }
        $response = (array) $response;
        foreach ($response as $session) {
            if (!is_object($session) || !property_exists($session, 'sessionId') || !property_exists($session, 'ipAddress')) {
                continue;
            }

            if ($session->ipAddress !== $ip) {
                continue;
            }

            $this->post('/api/captiveportal/session/disconnect', $query, [
                'sessionId' => $session->sessionId,
            ]);

            break;
        }

        return $this;
    }

    /**
     * @param array<int, string> $hostnames
     * @return $this
     * @throws BackendException
     */
    public function addAllowedHostnames(array $hostnames): self
    {
        $result = $this->get('/api/captiveportal/settings/get');
        $zones = $result->zone->zones->zone ?? null;
        if (!is_object($zones)) {
            throw new BackendException("Response is malformed");
        }
        $zones = (array) $zones;

        foreach ($zones as $uuid => $zone) {
            if (!is_object($zone) || !property_exists($zone, 'zoneid')) {
                continue;
            }
            if ((int)$zone->zoneid !== $this->zoneId) {
                continue;
            }

            $allowed = [];
            if (property_exists($zone, 'allowedAddresses') && is_object($zone->allowedAddresses)) {
                $zoneAllowed = (array) $zone->allowedAddresses;
                foreach ($zoneAllowed as $ip) {
                    $allowed[] = $ip->value;
                }
            }
            foreach ($hostnames as $hostname) {
                $ips = gethostbynamel($hostname);
                if ($ips === false) {
                    continue;
                }
                $allowed = array_merge($allowed, $ips);
            }

            $allowed = array_unique($allowed);

            $this->post("/api/captiveportal/settings/setZone/{$uuid}", [], [
                'zone' => [
                    'allowedAddresses' => implode(',', $allowed),
                ],
            ]);
        }

        return $this;
    }

    public function limitIp(string $ip): FirewallBackendInterface
    {
        $alias = $this->getAlias();
        $hosts = $this->getHosts($alias);

        $hosts[] = $ip;
        $hosts = array_unique($hosts);

        $this->updateAlias($alias, $hosts);
        return $this;
    }

    public function unlimitIp(string $ip): FirewallBackendInterface
    {
        $alias = $this->getAlias();
        $hosts = $this->getHosts($alias);

        foreach ($hosts as $index => $value) {
            if ($value === $ip) {
                unset($hosts[$index]);
            }
        }

        $this->updateAlias($alias, $hosts);
        return $this;
    }

    protected function getAlias(): \stdClass
    {
        $result = $this->get("/api/firewall/alias/getItem/{$this->aliasUuid}");
        foreach ($result->alias->type as $type => $data) {
            if ($data->selected === 1 && $type !== 'host') {
                throw new BackendException('Unable to work with aliases that are not host aliases');
            }
        }
        return $result;
    }

    protected function getHosts(\stdClass $alias): array
    {
        $hosts = [];
        foreach ($alias->alias->content as $aliasContent) {
            if ($aliasContent->selected === 1) {
                $hosts[] = $aliasContent->value;
            }
        }
        return $hosts;
    }

    protected function updateAlias(\stdClass $alias, array $hosts): void
    {
        $categories = [];
        foreach ($alias->alias->categories as $uuid => $cat) {
            if ($cat->selected === 1) {
                $categories[] = $uuid;
            }
        }

        $payload = (object)[
            'alias' => (object)[
                'categories' => implode(',', $categories),
                'content' => implode("\n", $hosts),
                'counters' => $alias->alias->counters,
                'description' => $alias->alias->description,
                'enabled' => $alias->alias->enabled,
                'interface' => '',
                'name' => $alias->alias->name,
                'proto' => '',
                'type' => 'host',
                'updatefreq' => $alias->alias->updatefreq,
            ],
            'network_content' => '',
        ];

        $response = $this->post("/api/firewall/alias/setItem/{$this->aliasUuid}", [], $payload);
        if ($response->result !== 'saved') {
            throw new BackendException('Unable to update aliases');
        }
        $this->post('/api/firewall/alias/reconfigure');
    }
}
