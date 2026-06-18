<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Services\Firewalls\Exceptions\BackendException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class OpnSenseClient
{
    public function __construct(
        protected Client $client,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws BackendException
     */
    public function get(string $uri, array $query = []): stdClass
    {
        $options = $this->makeOptions($query);
        try {
            Log::debug('[OpnSense] GET '.$uri);
            $response = $this->client->get($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     *
     * @throws BackendException
     */
    public function post(string $uri, array $query = [], array|stdClass|null $payload = []): stdClass
    {
        $options = $this->makeOptions($query, $payload);
        try {
            Log::debug('[OpnSense] POST '.$uri);
            $response = $this->client->post($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * Fetch the OPNsense system uptime in seconds.
     *
     * @throws BackendException
     */
    public function getUptime(): int
    {
        $response = $this->get('/api/diagnostics/system/system_time');
        $time = new CarbonImmutable($response->uptime);

        return (int) $time->diffInSeconds(CarbonImmutable::now());
    }

    /**
     * @throws BackendException
     */
    protected function decodeResponse(ResponseInterface $response): stdClass
    {
        $json = json_decode((string) $response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackendException('Unable to decode response');
        }

        return (object) $json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     * @return array<string, mixed>
     */
    protected function makeOptions(array $query = [], array|stdClass|null $payload = null): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        return $options;
    }
}
