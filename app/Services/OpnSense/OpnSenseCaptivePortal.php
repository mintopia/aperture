<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\ValueObjects\ReconcileResult;
use Throwable;

class OpnSenseCaptivePortal implements CaptivePortalInterface
{
    public function __construct(
        protected OpnSenseClient $client,
        protected int $zoneId,
    ) {}

    /**
     * @throws BackendException
     */
    public function addIp(string $ip, string $description): void
    {
        $payload = (object) [
            'user' => $description,
            'ip' => IpAddress::normalize($ip),
        ];
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $this->client->post('/api/captiveportal/session/connect', $query, $payload);
    }

    /**
     * @throws BackendException
     */
    public function removeIp(string $ip): void
    {
        $ip = IpAddress::normalize($ip);
        $query = [
            'zoneid' => $this->zoneId,
        ];
        $response = $this->client->get('/api/captiveportal/session/list', $query);
        $response = (array) $response;
        foreach ($response as $session) {
            if (! is_object($session) || ! property_exists($session, 'sessionId') || ! property_exists($session, 'ipAddress')) {
                continue;
            }

            if (IpAddress::normalize((string) $session->ipAddress) !== $ip) {
                continue;
            }

            $this->client->post('/api/captiveportal/session/disconnect', $query, [
                'sessionId' => $session->sessionId,
            ]);

            break;
        }
    }

    /**
     * @param  array<int, string>  $hostnames
     *
     * @throws BackendException
     */
    public function addAllowedHostnames(array $hostnames): void
    {
        $result = $this->client->get('/api/captiveportal/settings/get');
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

            $this->client->post('/api/captiveportal/settings/setZone/'.$uuid, [], [
                'zone' => [
                    'allowedAddresses' => implode(',', $allowed),
                ],
            ]);
        }
    }

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        $currentIps = $this->fetchConnectedIps();
        /** @var array<int, string> $desiredIps */
        $desiredIps = IpAddress::where('internet_enabled', true)->pluck('address')->all();
        $desiredIps = array_map(IpAddress::normalize(...), $desiredIps);

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
                    $this->addIp($ip, 'Reconciled');
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

    /**
     * Fetch IPs currently connected via the captive portal.
     *
     * @return array<int, string>
     */
    protected function fetchConnectedIps(): array
    {
        $query = ['zoneid' => $this->zoneId];
        $response = $this->client->get('/api/captiveportal/session/list', $query);
        $sessions = (array) $response;

        $ips = [];
        foreach ($sessions as $session) {
            if (! is_object($session) || ! property_exists($session, 'ipAddress')) {
                continue;
            }

            $ips[] = IpAddress::normalize((string) $session->ipAddress);
        }

        return array_unique($ips);
    }
}
