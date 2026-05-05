<?php

declare(strict_types=1);

namespace App\Services\Seatpicker;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeatpickerSyncService
{
    public function sync(): SyncResult
    {
        $config = $this->getConfig();
        if ($config === null) {
            return new SyncResult(success: false, message: 'Seatpicker integration is not configured or disabled.');
        }

        $client = $this->buildClient($config);
        $synced = 0;
        $page = 1;

        do {
            $url = '/api/v1/tickets';
            $response = $client->get($url, ['event' => $config['event_code'], 'page' => $page]);

            if (! $response->successful()) {
                $fullUrl = $config['endpoint'].$url;
                $body = $response->body();
                $message = sprintf('API request failed: %s returned HTTP %d — %s', $fullUrl, $response->status(), $body);

                Log::warning('[Seatpicker] '.$message);

                return new SyncResult(success: false, message: $message);
            }

            $data = $response->json();
            $tickets = $data['data'] ?? [];

            foreach ($tickets as $ticket) {
                $email = $ticket['user']['data']['email'] ?? null;
                $seatLabel = $ticket['seat']['data']['label'] ?? null;

                if ($email === null || $seatLabel === null) {
                    continue;
                }

                $user = User::where('email', $email)->first();
                if ($user === null) {
                    continue;
                }

                UserParameter::updateOrCreate(
                    ['user_id' => $user->id, 'key' => 'seat'],
                    ['value' => $seatLabel],
                );
                $synced++;
            }

            $page++;
            $lastPage = $data['meta']['pagination']['total_pages'] ?? 1;
        } while ($page <= $lastPage);

        return new SyncResult(success: true, message: sprintf('Synced %d seat assignments.', $synced));
    }

    /**
     * @return array{endpoint: string, api_key: string, event_code: string, verify_ssl: bool}|null
     */
    private function getConfig(): ?array
    {
        $enabled = IntegrationConfig::getValue('seatpicker', 'enabled');
        if ($enabled !== '1') {
            return null;
        }

        $endpoint = IntegrationConfig::getValue('seatpicker', 'endpoint');
        $apiKey = IntegrationConfig::getValue('seatpicker', 'api_key');
        $eventCode = IntegrationConfig::getValue('seatpicker', 'event_code');

        if (! $endpoint || ! $apiKey || ! $eventCode) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'api_key' => $apiKey,
            'event_code' => $eventCode,
            'verify_ssl' => (bool) (IntegrationConfig::getValue('seatpicker', 'verify_ssl') ?? true),
        ];
    }

    /**
     * @param  array{endpoint: string, api_key: string, event_code: string, verify_ssl: bool}  $config
     */
    private function buildClient(array $config): PendingRequest
    {
        return Http::withOptions(['verify' => $config['verify_ssl']])
            ->baseUrl($config['endpoint'])
            ->withToken($config['api_key'])
            ->acceptJson()
            ->timeout(30);
    }
}
