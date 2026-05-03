<?php

declare(strict_types=1);

namespace App\Services\Seatpicker;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
            $response = $client->get(sprintf('/api/v1/events/%s/tickets', $config['event_code']), ['page' => $page]);

            if (! $response->successful()) {
                return new SyncResult(success: false, message: 'API request failed with status '.$response->status());
            }

            $data = $response->json();
            $tickets = $data['data'] ?? [];

            foreach ($tickets as $ticket) {
                $email = $ticket['user']['email'] ?? null;
                $seatLabel = $ticket['seat']['label'] ?? null;

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
            $lastPage = $data['meta']['last_page'] ?? $data['last_page'] ?? 1;
        } while ($page <= $lastPage);

        return new SyncResult(success: true, message: sprintf('Synced %d seat assignments.', $synced));
    }

    /**
     * @return array{endpoint: string, api_token: string, event_code: string}|null
     */
    private function getConfig(): ?array
    {
        $enabled = IntegrationConfig::getValue('seatpicker', 'enabled');
        if ($enabled !== '1') {
            return null;
        }

        $endpoint = IntegrationConfig::getValue('seatpicker', 'endpoint');
        $apiToken = IntegrationConfig::getValue('seatpicker', 'api_token');
        $eventCode = IntegrationConfig::getValue('seatpicker', 'event_code');

        if (! $endpoint || ! $apiToken || ! $eventCode) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'api_token' => $apiToken,
            'event_code' => $eventCode,
        ];
    }

    /**
     * @param  array{endpoint: string, api_token: string, event_code: string}  $config
     */
    private function buildClient(array $config): PendingRequest
    {
        return Http::baseUrl($config['endpoint'])
            ->withToken($config['api_token'])
            ->acceptJson()
            ->timeout(30);
    }
}
