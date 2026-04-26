<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\ValueObjects\ReconcileResult;
use stdClass;
use Throwable;

class OpnSenseRateLimiter implements RateLimitingInterface
{
    public function __construct(
        protected OpnSenseClient $client,
        protected string $uploadRuleUuid,
        protected string $downloadRuleUuid,
    ) {}

    public function limitIp(string $ip): void
    {
        $this->addHostToRule($this->downloadRuleUuid, $ip, 'destination');
        $this->addHostToRule($this->uploadRuleUuid, $ip, 'source');
        $this->applyShaperRules();
    }

    public function unlimitIp(string $ip): void
    {
        $this->removeHostFromRule($this->downloadRuleUuid, $ip, 'destination');
        $this->removeHostFromRule($this->uploadRuleUuid, $ip, 'source');
        $this->applyShaperRules();
    }

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        $currentIps = $this->fetchRateLimitedIps();
        $desiredIps = IpAddress::where('rate_limit_enabled', true)->pluck('address')->all();

        /** @var array<string, true> $currentIpLookup */
        $currentIpLookup = array_flip($currentIps);
        /** @var array<string, true> $desiredIpLookup */
        $desiredIpLookup = array_flip($desiredIps);

        /** @var array<int, string> $added */
        $added = [];
        /** @var array<int, string> $removed */
        $removed = [];
        /** @var array<int, string> $unchanged */
        $unchanged = [];
        /** @var array<int, string> $errors */
        $errors = [];

        foreach ($desiredIps as $ip) {
            if (isset($currentIpLookup[$ip])) {
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

        foreach ($currentIps as $ip) {
            if (isset($desiredIpLookup[$ip])) {
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
        return $this->client->get('/api/trafficshaper/settings/get_rule/'.$uuid);
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
     *
     * @throws BackendException
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

        $response = $this->client->post('/api/trafficshaper/settings/set_rule/'.$uuid, [], $payload);
        if (! property_exists($response, 'result') || $response->result !== 'saved') {
            throw new BackendException('Unable to update shaper rule');
        }
    }

    protected function applyShaperRules(): void
    {
        $this->client->post('/api/trafficshaper/service/reconfigure');
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
