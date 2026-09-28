<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Http\ExternalHttp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use stdClass;

class OpnSenseClient
{
    public function __construct(
        protected string $endpoint,
        protected string $key,
        protected string $secret,
        protected bool $verifySsl = true,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws BackendException
     */
    public function get(string $uri, array $query = []): stdClass
    {
        try {
            Log::debug('[OpnSense] GET '.$uri);
            $response = $this->request()->get($uri, $query);

            return $this->decodeResponse($response);
        } catch (HttpClientException $exception) {
            throw new BackendException('Error from Opnsense: '.$exception->getMessage(), $exception->getCode(), $exception);
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
        try {
            Log::debug('[OpnSense] POST '.$uri);
            $request = $this->request();
            $target = $this->withQuery($uri, $query);
            $response = $payload instanceof stdClass
                ? $request->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')->post($target)
                : $request->post($target, $payload ?? []);

            return $this->decodeResponse($response);
        } catch (HttpClientException $exception) {
            throw new BackendException('Error from Opnsense: '.$exception->getMessage(), $exception->getCode(), $exception);
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
    protected function decodeResponse(Response $response): stdClass
    {
        $json = json_decode($response->body());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackendException('Unable to decode response');
        }

        return (object) $json;
    }

    protected function request(): PendingRequest
    {
        return ExternalHttp::request($this->endpoint, $this->verifySsl)
            ->withBasicAuth($this->key, $this->secret)
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function withQuery(string $uri, array $query): string
    {
        return $query === [] ? $uri : $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }
}
