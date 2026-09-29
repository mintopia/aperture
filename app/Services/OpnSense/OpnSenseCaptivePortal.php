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
        $response = $this->client->post('/api/captiveportal/session/connect', $query, $payload);

        if (property_exists($response, 'clientState') && $response->clientState !== 'AUTHORIZED') {
            throw new BackendException(sprintf(
                'OPNsense did not authorize %s: clientState is "%s"',
                $payload->ip,
                is_scalar($response->clientState) ? (string) $response->clientState : get_debug_type($response->clientState),
            ));
        }
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
