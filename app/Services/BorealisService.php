<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Borealis\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;

class BorealisService
{
    public function __construct(protected string $clientId, protected string $clientSecret, protected string $endpoint) {}

    public function check(string $deviceCode): stdClass
    {
        $params = [
            'device_code' => $deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ];

        return $this->makeRequest('oauth2/token', $params);
    }

    public function getDeviceCodeRaw(string $scope): stdClass
    {
        return $this->makeRequest('oauth2/device', [
            'scope' => $scope,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function makeRequest(string $url, array $params = []): stdClass
    {
        $params['client_id'] = $this->clientId;
        $params['client_secret'] = $this->clientSecret;
        $response = Http::baseUrl($this->endpoint)->asForm()->post($url, $params);

        if ($response->status() === 403) {
            $data = $this->decodeResponse($response);
            throw new RequestException($data->error, 403);
        }

        $response->throw();

        return $this->decodeResponse($response);
    }

    protected function decodeResponse(Response $response): stdClass
    {
        $data = json_decode($response->body());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestException('Unable to decode response');
        }

        return $data;
    }
}
