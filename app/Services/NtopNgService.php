<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use stdClass;

class NtopNgService
{
    protected Client $client;

    public function __construct(string $endpoint, string $username, string $password, protected int $interface)
    {
        $this->client = new Client([
            'base_uri' => $endpoint,
            'auth' => [
                $username,
                $password,
            ],
        ]);
    }

    public function getStats(string $ip): stdClass
    {
        $response = $this->client->get('/lua/rest/v2/get/host/data.lua', [
            'query' => [
                'ifid' => $this->interface,
                'host' => $ip,
            ],
        ]);

        /** @var stdClass */
        return json_decode((string) $response->getBody());
    }
}
