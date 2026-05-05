<?php

declare(strict_types=1);

namespace App\Services\Seatpicker;

use Illuminate\Support\Facades\Http;
use Throwable;

class SeatpickerApiService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{events: list<array{code: string, name: string}>, error?: string}
     */
    public static function getEvents(array $config): array
    {
        try {
            $endpoint = rtrim($config['endpoint'] ?? '', '/');

            if ($endpoint === '') {
                return ['events' => [], 'error' => 'Seatpicker endpoint is not configured.'];
            }

            $verifySsl = (bool) ($config['verify_ssl'] ?? true);

            $response = Http::withOptions(['verify' => $verifySsl])
                ->withToken($config['api_key'] ?? '')
                ->acceptJson()
                ->timeout(10)
                ->get($endpoint.'/api/v1/events');

            if (! $response->successful()) {
                return [
                    'events' => [],
                    'error' => 'Failed to fetch events (HTTP '.$response->status().').',
                ];
            }

            /** @var array<int, array{code?: string, name?: string}> $events */
            $events = $response->json('data', []);

            return ['events' => array_values(array_map(function (array $e): array {
                $code = $e['code'] ?? '';

                return [
                    'code' => $code,
                    'name' => $e['name'] ?? 'Event '.$code,
                ];
            }, $events))];
        } catch (Throwable $throwable) {
            return ['events' => [], 'error' => $throwable->getMessage()];
        }
    }
}
