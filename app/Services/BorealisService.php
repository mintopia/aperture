<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\AuthProvider;
use App\Services\Borealis\DeviceCode;
use App\Services\Borealis\RequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

class BorealisService
{
    protected Client $client;
    public function __construct(protected string $clientId, protected string $clientSecret, protected string $endpoint)
    {
        $this->client = new Client([
            'base_uri' => $this->endpoint,
        ]);
    }

    public function getDeviceCode(AuthProvider $provider): DeviceCode
    {
        $response = $this->makeRequest('oauth2/device', [
            'scope' => $provider->code,
        ]);
        $code = new DeviceCode($this, $provider->code);
        $code->parse($response);
        return $code;
    }

    public function check(string $deviceCode): mixed
    {
        $params = [
            'device_code' => $deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ];
        return $this->makeRequest('oauth2/token', $params);
    }

    protected function makeRequest(string $url, array $params = []): mixed
    {
        $params['client_id'] = $this->clientId;
        $params['client_secret'] = $this->clientSecret;
        try {
            $response = $this->client->post($url, [
                'form_params' => $params,
            ]);
            return $this->decodeResponse($response);
        } catch (ClientException $e) {
            if ($e->getCode() === 403) {
                $data = $this->decodeResponse($e->getResponse());
                throw new RequestException($data->error, $e->getCode());
            }
            throw $e;
        }
    }

    protected function decodeResponse(ResponseInterface $response): mixed
    {
        $json = $response->getBody()->getContents();
        $data = json_decode($json);
        if ($data === false) {
            throw new RequestException('Unable to decode response');
        }
        return $data;
    }
}
