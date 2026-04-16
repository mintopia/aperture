<?php

declare(strict_types=1);

namespace App\Services\Firewalls;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stateless admin/config API methods for OPNsense.
 *
 * These complement the runtime OpnSense service (which uses a DI'd Guzzle
 * client bound to a specific zone). The methods here accept a raw config
 * array (merged DB + unsaved request values) and use the Http facade so
 * they work without a pre-wired service instance.
 */
class OpnSenseApiService
{
    /**
     * Fetch traffic shaper rules from OPNsense.
     *
     * @param  array<string, mixed>  $config  Merged DB + request config
     * @return array{rules: list<array{uuid: string, description: string}>, error?: string}
     */
    public static function getShaperRules(array $config): array
    {
        try {
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $key = $config['key'] ?? '';
            $secret = $config['secret'] ?? '';

            if ($endpoint === '') {
                return ['rules' => [], 'error' => 'OPNsense endpoint is not configured.'];
            }

            if ($key === '' || $secret === '') {
                return ['rules' => [], 'error' => 'OPNsense API key and secret are required.'];
            }

            $response = self::makeClient($config)
                ->post($endpoint.'/api/trafficshaper/settings/search_rules', [
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
        } catch (Throwable $e) {
            return ['rules' => [], 'error' => 'Failed to fetch shaper rules: '.$e->getMessage()];
        }
    }

    /**
     * Fetch captive portal zones from OPNsense.
     *
     * @param  array<string, mixed>  $config  Merged DB + request config
     * @return array{zones: list<array{id: string, name: string}>, error?: string}
     */
    public static function getZones(array $config): array
    {
        try {
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $key = $config['key'] ?? '';
            $secret = $config['secret'] ?? '';

            if ($endpoint === '') {
                return ['zones' => [], 'error' => 'OPNsense endpoint is not configured.'];
            }

            if ($key === '' || $secret === '') {
                return ['zones' => [], 'error' => 'OPNsense API key and secret are required.'];
            }

            $response = self::makeClient($config)
                ->get($endpoint.'/api/captiveportal/settings/get');

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

            usort($zones, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

            return ['zones' => $zones];
        } catch (Throwable $e) {
            return ['zones' => [], 'error' => 'Failed to fetch zones: '.$e->getMessage()];
        }
    }

    /**
     * Build an authenticated Http client pre-configured with OPNsense credentials.
     *
     * @param  array<string, mixed>  $config
     */
    private static function makeClient(array $config): PendingRequest
    {
        return Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
            ->withBasicAuth($config['key'] ?? '', $config['secret'] ?? '')
            ->timeout(10);
    }
}
