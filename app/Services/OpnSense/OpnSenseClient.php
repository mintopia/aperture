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
use Throwable;

class OpnSenseClient
{
    public function __construct(
        protected string $endpoint,
        protected string $key,
        protected string $secret,
        protected bool $verifySsl = true,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            rtrim((string) ($config['endpoint'] ?? ''), '/'),
            (string) ($config['key'] ?? ''),
            (string) ($config['secret'] ?? ''),
            (bool) ($config['verify_ssl'] ?? true),
        );
    }

    public function request(?int $timeout = null): PendingRequest
    {
        $request = ExternalHttp::request($this->endpoint, $this->verifySsl)
            ->withBasicAuth($this->key, $this->secret)
            ->throw();

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws BackendException
     */
    public function get(string $uri, array $query = []): stdClass
    {
        try {
            Log::debug('[OpnSense] GET '.$uri);

            return $this->decodeResponse($this->request()->get($uri, $query));
        } catch (HttpClientException $httpClientException) {
            throw new BackendException('Error from Opnsense: '.$httpClientException->getMessage(), $httpClientException->getCode(), $httpClientException);
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
        } catch (HttpClientException $httpClientException) {
            throw new BackendException('Error from Opnsense: '.$httpClientException->getMessage(), $httpClientException->getCode(), $httpClientException);
        }
    }

    /**
     * @return array{rules: list<array{uuid: string, description: string}>, error?: string}
     */
    public function getShaperRules(): array
    {
        try {
            $error = $this->validateConfig();
            if ($error !== null) {
                return ['rules' => [], 'error' => $error];
            }

            $data = $this->request(10)->post('/api/trafficshaper/settings/search_rules', [
                'current' => 1,
                'rowCount' => -1,
                'searchPhrase' => '',
            ])->json();

            $rules = [['uuid' => '', 'description' => 'None (no rate limiting)']];

            foreach ($data['rows'] ?? [] as $rule) {
                $rules[] = [
                    'uuid' => $rule['uuid'] ?? '',
                    'description' => ($rule['description'] ?? 'Unnamed rule').' (seq: '.($rule['sequence'] ?? '?').')',
                ];
            }

            return ['rules' => $rules];
        } catch (Throwable $throwable) {
            return ['rules' => [], 'error' => 'Failed to fetch shaper rules: '.$throwable->getMessage()];
        }
    }

    /**
     * @return array{zones: list<array{id: string, name: string}>, error?: string}
     */
    public function getZones(): array
    {
        try {
            $error = $this->validateConfig();
            if ($error !== null) {
                return ['zones' => [], 'error' => $error];
            }

            $data = $this->request(10)->get('/api/captiveportal/settings/get')->json();

            $zones = [];

            foreach ($data['zone']['zones']['zone'] ?? [] as $zone) {
                $zoneId = $zone['zoneid'] ?? '';
                $description = $zone['description'] ?? 'Zone '.$zoneId;
                $zones[] = [
                    'id' => (string) $zoneId,
                    'name' => $description.' (ID: '.$zoneId.')',
                ];
            }

            usort($zones, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            return ['zones' => $zones];
        } catch (Throwable $throwable) {
            return ['zones' => [], 'error' => 'Failed to fetch zones: '.$throwable->getMessage()];
        }
    }

    /**
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

    private function validateConfig(): ?string
    {
        if ($this->endpoint === '') {
            return 'OPNsense endpoint is not configured.';
        }

        if ($this->key === '' || $this->secret === '') {
            return 'OPNsense API credentials are not configured.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function withQuery(string $uri, array $query): string
    {
        return $query === [] ? $uri : $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }
}
