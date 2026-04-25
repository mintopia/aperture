<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\ValueObjects\HostBytes;
use GuzzleHttp\Client;
use stdClass;
use Throwable;

class NtopNgService implements HostStatsProviderInterface
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

    public function getHostBytes(string $ipAddress): ?HostBytes
    {
        try {
            $stats = $this->getRawStats($ipAddress);
            $rcvd = 'bytes.rcvd';
            $sent = 'bytes.sent';

            return new HostBytes(
                received: (int) ($stats->rsp->$rcvd ?? 0),
                sent: (int) ($stats->rsp->$sent ?? 0),
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function getRawStats(string $ip): stdClass
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
