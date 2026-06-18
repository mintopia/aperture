<?php

declare(strict_types=1);

namespace App\Services\PiHole;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stateless admin/config API methods for Pi-hole.
 *
 * These complement the runtime PiHoleService (which uses a DI'd Guzzle
 * client and cached session). The methods here accept a raw config array
 * (merged DB + unsaved request values) and use the Http facade so they
 * work without a pre-wired service instance.
 */
class PiHoleApiService
{
    /**
     * Fetch Pi-hole groups by authenticating and then querying /api/groups.
     *
     * @param  array<string, mixed>  $config  Merged DB + request config
     * @return array{groups: list<array{id: int, name: string, enabled: bool}>, error?: string}
     */
    public static function getGroups(array $config): array
    {
        try {
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $password = $config['password'] ?? '';

            if ($endpoint === '') {
                return ['groups' => [], 'error' => 'Pi-hole endpoint is not configured.'];
            }

            if ($password === '') {
                return ['groups' => [], 'error' => 'Pi-hole password is not configured.'];
            }

            $verifySsl = (bool) ($config['verify_ssl'] ?? true);

            // Step 1: Authenticate — mirrors PiHoleService::getSessionId()
            $authResponse = Http::withOptions(['verify' => $verifySsl])
                ->timeout(10)
                ->asJson()
                ->post($endpoint.'/api/auth', ['password' => $password]);

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

            // Step 2: Fetch groups — uses X-FTL-SID header per Pi-hole v6 API
            $response = Http::withOptions(['verify' => $verifySsl])
                ->withHeaders(['X-FTL-SID' => $sid])
                ->timeout(10)
                ->get($endpoint.'/api/groups');

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
}
