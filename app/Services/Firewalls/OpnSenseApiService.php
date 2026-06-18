<?php

declare(strict_types=1);

namespace App\Services\Firewalls;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Admin/config API methods for OPNsense.
 *
 * These complement the runtime OpnSense service (which uses a DI'd Guzzle
 * client bound to a specific zone). This service accepts a config array
 * (merged DB + unsaved request values) via the constructor and uses the
 * Http facade so it works without a pre-wired Guzzle instance.
 */
class OpnSenseApiService
{
    private readonly string $endpoint;

    private readonly string $key;

    private readonly string $secret;

    private readonly bool $verifySsl;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->key = (string) ($config['key'] ?? '');
        $this->secret = (string) ($config['secret'] ?? '');
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? true);
    }

    /**
     * Validate that endpoint and credentials are present.
     *
     * Returns an error string when invalid, or null when valid.
     */
    private function validateConfig(): ?string
    {
        if ($this->endpoint === '') {
            return 'OPNsense endpoint is not configured.';
        }

        if ($this->key === '' || $this->secret === '') {
            return 'OPNsense API credentials are not configured.';
        }

        return null;
    }

    /**
     * Fetch traffic shaper rules from OPNsense.
     *
     * @return array{rules: list<array{uuid: string, description: string}>, error?: string}
     */
    public function getShaperRules(): array
    {
        try {
            $error = $this->validateConfig();
            if ($error !== null) {
                return ['rules' => [], 'error' => $error];
            }

            $response = $this->makeClient()
                ->post($this->endpoint.'/api/trafficshaper/settings/search_rules', [
                    'current' => 1,
                    'rowCount' => -1,
                    'searchPhrase' => '',
                ]);

            $response->throw();
            $data = $response->json();

            $rules = [['uuid' => '', 'description' => 'None (no rate limiting)']];

            foreach ($data['rows'] ?? [] as $rule) {
                $rules[] = [
                    'uuid' => $rule['uuid'] ?? '',
                    'description' => ($rule['description'] ?? 'Unnamed rule').' (seq: '.($rule['sequence'] ?? '?').')',
                ];
            }

            return ['rules' => $rules];
        } catch (Throwable $throwable) {
            return ['rules' => [], 'error' => 'Failed to fetch shaper rules: '.$throwable->getMessage()];
        }
    }

    /**
     * Fetch captive portal zones from OPNsense.
     *
     * @return array{zones: list<array{id: string, name: string}>, error?: string}
     */
    public function getZones(): array
    {
        try {
            $error = $this->validateConfig();
            if ($error !== null) {
                return ['zones' => [], 'error' => $error];
            }

            $response = $this->makeClient()
                ->get($this->endpoint.'/api/captiveportal/settings/get');

            $response->throw();
            $data = $response->json();

            $zones = [];
            $zonesData = $data['zone']['zones']['zone'] ?? [];

            foreach ($zonesData as $zone) {
                $zoneId = $zone['zoneid'] ?? '';
                $description = $zone['description'] ?? 'Zone '.$zoneId;
                $zones[] = [
                    'id' => (string) $zoneId,
                    'name' => $description.' (ID: '.$zoneId.')',
                ];
            }

            usort($zones, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            return ['zones' => $zones];
        } catch (Throwable $throwable) {
            return ['zones' => [], 'error' => 'Failed to fetch zones: '.$throwable->getMessage()];
        }
    }

    /**
     * Build an authenticated Http client pre-configured with OPNsense credentials.
     */
    private function makeClient(): PendingRequest
    {
        return Http::withOptions(['verify' => $this->verifySsl])
            ->withBasicAuth($this->key, $this->secret)
            ->timeout(10);
    }
}
