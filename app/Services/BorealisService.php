<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Borealis\RequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class BorealisService
{
    protected Client $client;

    public function __construct(protected string $clientId, protected string $clientSecret, protected string $endpoint)
    {
        $this->client = new Client([
            'base_uri' => $this->endpoint,
        ]);
    }

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
        try {
            $response = $this->client->post($url, [
                'form_params' => $params,
            ]);

            return $this->decodeResponse($response);
        } catch (ClientException $clientException) {
            if ($clientException->getCode() === 403) {
                $data = $this->decodeResponse($clientException->getResponse());
                throw new RequestException($data->error, $clientException->getCode(), $clientException);
            }

            throw $clientException;
        }
    }

    protected function decodeResponse(ResponseInterface $response): stdClass
    {
        $json = $response->getBody()->getContents();
        $data = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestException('Unable to decode response');
        }

        return $data;
    }
}
