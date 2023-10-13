<?php
namespace App\Services;

use GuzzleHttp\Client;

class NtopNgService {

    protected Client $client;
    public function __construct(string $endpoint, string $username, string $password, protected int $interface)
    {
        $this->client = new Client([
            'base_uri' => $endpoint,
            'auth' => [
                $username,
                $password
            ],
        ]);
    }

    public function getStats(string $ip): object
    {
        $response = $this->client->get('/lua/rest/v2/get/host/data.lua', [
            'query' => [
                'ifid' => $this->interface,
                'host' => $ip,
            ],
        ]);
        return json_decode((string)$response->getBody());
    }
}
