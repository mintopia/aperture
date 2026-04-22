<?php

namespace App\Services\Firewalls;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\ValueObjects\ReconcileResult;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

class OpnSense implements FirewallBackendInterface
{
    protected int $zoneId;

    protected Client $client;

    protected string $uploadRuleUuid;

    protected string $downloadRuleUuid;

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws BackendException
     */
    protected function get(string $uri, array $query = []): stdClass
    {
        $options = $this->makeOptions($query);
        try {
            Log::debug('[OpnSense] GET '.$uri);
            $response = $this->client->get($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     *
     * @throws BackendException
     */
    protected function post(string $uri, array $query = [], array|stdClass|null $payload = []): stdClass
    {
        $options = $this->makeOptions($query, $payload);
        try {
            Log::debug('[OpnSense] POST '.$uri);
            $response = $this->client->post($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * @throws BackendException
     */
    protected function decodeResponse(ResponseInterface $response): stdClass
    {
        $json = json_decode($response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackendException('Unable to decode response');
        }

        return (object) $json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     * @return array<string, mixed>
     */
    protected function makeOptions(array $query = [], array|stdClass|null $payload = null): array
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
     * @throws BackendException
     */
    public function __construct(
        string $endpoint = '',
        string $key = '',
        string $secret = '',
        int $zoneId = 0,
        bool $verify = true,
        string $uploadRuleUuid = '',
        string $downloadRuleUuid = '',
    ) {
        $this->zoneId = $zoneId;
        $this->uploadRuleUuid = $uploadRuleUuid;
        $this->downloadRuleUuid = $downloadRuleUuid;

        $this->client = new Client([
            'verify' => $verify,
            'base_uri' => $endpoint,
            'auth' => [
                $key,
                $secret,
            ],
        ]);
    }

    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function updateIp(string $ip, string $description): self
    {
        $payload = (object) [
            'user' => $description,
            'ip' => $ip,
        ];
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $this->post('/api/captiveportal/session/connect', $query, $payload);

        return $this;
    }

    public function getUptime(): int
    {
        $response = $this->get('/api/diagnostics/system/system_time');
        $time = new CarbonImmutable($response->uptime);

        return (int) $time->diffInSeconds(CarbonImmutable::now());
    }

    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function removeIp(string $ip): self
    {
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $response = $this->get('/api/captiveportal/session/list', $query);
        $response = (array) $response;
        foreach ($response as $session) {
            if (! is_object($session) || ! property_exists($session, 'sessionId') || ! property_exists($session, 'ipAddress')) {
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
     * @param  array<int, string>  $hostnames
     * @return $this
     *
     * @throws BackendException
     */
    public function addAllowedHostnames(array $hostnames): self
    {
        $result = $this->get('/api/captiveportal/settings/get');
        $zones = $result->zone->zones->zone ?? null;
        if (! is_object($zones)) {
            throw new BackendException('Response is malformed');
        }

        $zones = (array) $zones;

        foreach ($zones as $uuid => $zone) {
            if (! is_object($zone) || ! property_exists($zone, 'zoneid')) {
                continue;
            }

            if ((int) $zone->zoneid !== $this->zoneId) {
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

            $this->post('/api/captiveportal/settings/setZone/'.$uuid, [], [
                'zone' => [
                    'allowedAddresses' => implode(',', $allowed),
                ],
            ]);
        }

        return $this;
    }

    public function limitIp(string $ip): FirewallBackendInterface
    {
        $this->addHostToRule($this->downloadRuleUuid, $ip, 'destination');
        $this->addHostToRule($this->uploadRuleUuid, $ip, 'source');
        $this->applyShaperRules();

        return $this;
    }

    public function unlimitIp(string $ip): FirewallBackendInterface
    {
        $this->removeHostFromRule($this->downloadRuleUuid, $ip, 'destination');
        $this->removeHostFromRule($this->uploadRuleUuid, $ip, 'source');
        $this->applyShaperRules();

        return $this;
    }

    protected function addHostToRule(string $uuid, string $ip, string $propName): void
    {
        $rule = $this->getShaperRule($uuid);
        $hosts = $this->filter($rule->rule->{$propName});

        $hosts[] = $ip;
        $hosts = array_unique($hosts);

        $this->updateShaperRule($uuid, $rule, $propName, $hosts);
    }

    protected function removeHostFromRule(string $uuid, string $ip, string $propName): void
    {
        $rule = $this->getShaperRule($uuid);
        $hosts = $this->filter($rule->rule->{$propName});

        foreach ($hosts as $index => $value) {
            if ($value === $ip) {
                unset($hosts[$index]);
            }
        }

        $this->updateShaperRule($uuid, $rule, $propName, $hosts);
    }

    protected function getShaperRule(string $uuid): stdClass
    {
        return $this->get('/api/trafficshaper/settings/get_rule/'.$uuid);
    }

    /**
     * @param  array<string, stdClass>|stdClass  $objects
     * @return array<int, string>
     */
    protected function filter(array|stdClass $objects): array
    {
        if (! is_array($objects)) {
            $objects = (array) $objects;
        }

        $result = [];
        foreach ($objects as $value => $obj) {
            if ($obj->selected) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $hosts
     */
    protected function updateShaperRule(string $uuid, stdClass $rule, string $propName, array $hosts): void
    {
        $payload = (object) [
            'rule' => (object) [
                'description' => $rule->rule->description,
                'destination_not' => $rule->rule->destination_not,
                'direction' => implode(',', $this->filter($rule->rule->direction)),
                'dscp' => implode(',', $this->filter($rule->rule->dscp)),
                'dst_port' => $rule->rule->dst_port,
                'enabled' => $rule->rule->enabled,
                'interface' => implode(',', $this->filter($rule->rule->interface)),
                'interface2' => implode(',', $this->filter($rule->rule->interface2)),
                'iplen' => $rule->rule->iplen,
                'proto' => implode(',', $this->filter($rule->rule->proto)),
                'sequence' => $rule->rule->sequence,
                'source_not' => $rule->rule->source_not,
                'src_port' => $rule->rule->src_port,
                'target' => implode(',', $this->filter($rule->rule->target)),
            ],
        ];

        $payload->rule->{$propName} = implode(',', $hosts);

        $response = $this->post('/api/trafficshaper/settings/set_rule/'.$uuid, [], $payload);
        if (! property_exists($response, 'result') || $response->result !== 'saved') {
            throw new BackendException('Unable to update shaper rule');
        }
    }

    protected function applyShaperRules(): void
    {
        $this->post('/api/trafficshaper/service/reconfigure');
    }

    public function reconcileInternet(bool $dryRun = false): ReconcileResult
    {
        $connectedIps = $this->fetchConnectedIps();

        $desiredEnabled = IpAddress::where('internet_enabled', true)
            ->pluck('address')
            ->all();
        $desiredDisabled = IpAddress::where('internet_enabled', false)
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

        foreach ($desiredEnabled as $ip) {
            if (in_array($ip, $connectedIps, true)) {
                $unchanged[] = $ip;

                continue;
            }

            $added[] = $ip;
            if (! $dryRun) {
                try {
                    $this->updateIp($ip, 'Reconciled');
                } catch (Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        foreach ($desiredDisabled as $ip) {
            if (! in_array($ip, $connectedIps, true)) {
                $unchanged[] = $ip;

                continue;
            }

            $removed[] = $ip;
            if (! $dryRun) {
                try {
                    $this->removeIp($ip);
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

    public function reconcileRateLimits(bool $dryRun = false): ReconcileResult
    {
        $rateLimitedIps = $this->fetchRateLimitedIps();

        $desiredEnabled = IpAddress::where('rate_limit_enabled', true)
            ->pluck('address')
            ->all();
        $desiredDisabled = IpAddress::where('rate_limit_enabled', false)
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

        foreach ($desiredEnabled as $ip) {
            if (in_array($ip, $rateLimitedIps, true)) {
                $unchanged[] = $ip;

                continue;
            }

            $added[] = $ip;
            if (! $dryRun) {
                try {
                    $this->limitIp($ip);
                } catch (Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        foreach ($desiredDisabled as $ip) {
            if (! in_array($ip, $rateLimitedIps, true)) {
                $unchanged[] = $ip;

                continue;
            }

            $removed[] = $ip;
            if (! $dryRun) {
                try {
                    $this->unlimitIp($ip);
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
     * Fetch IPs currently connected via the captive portal.
     *
     * @return array<int, string>
     */
    protected function fetchConnectedIps(): array
    {
        $query = ['zoneid' => $this->zoneId];
        $response = $this->get('/api/captiveportal/session/list', $query);
        $sessions = (array) $response;

        $ips = [];
        foreach ($sessions as $session) {
            if (! is_object($session) || ! property_exists($session, 'ipAddress')) {
                continue;
            }

            $ips[] = $session->ipAddress;
        }

        return array_unique($ips);
    }

    /**
     * Fetch IPs currently rate-limited via the traffic shaper download rule.
     *
     * @return array<int, string>
     */
    protected function fetchRateLimitedIps(): array
    {
        $rule = $this->getShaperRule($this->downloadRuleUuid);

        return $this->filter($rule->rule->destination);
    }
}
