<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpLease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class KeaDhcpService extends NullDhcpService
{
    private const STATE_DEFAULT = 0;

    public function __construct(private readonly KeaClient $client) {}

    public function getLease(string $ipAddress): ?DhcpLease
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        try {
            $entry = $this->client->sendCommand('lease4-get', ['ip-address' => $ipAddress]);
        } catch (Throwable $e) {
            Log::warning('Kea lease4-get failed', ['ip' => $ipAddress, 'error' => $e->getMessage()]);

            return null;
        }

        $lease = $entry['arguments'] ?? null;

        if (! is_array($lease) || $lease === []) {
            return null;
        }

        if (($lease['state'] ?? null) !== self::STATE_DEFAULT) {
            return null;
        }

        if (! is_int($lease['cltt'] ?? null) || ! is_int($lease['valid-lft'] ?? null)) {
            return null;
        }

        $expiresAt = $lease['cltt'] + $lease['valid-lft'];

        if ($expiresAt <= now()->getTimestamp()) {
            return null;
        }

        $hwAddress = $lease['hw-address'] ?? null;
        $hostname = $lease['hostname'] ?? null;

        return new DhcpLease(
            ip: $ipAddress,
            mac: is_string($hwAddress) && $hwAddress !== '' ? $hwAddress : null,
            hostname: is_string($hostname) ? $hostname : '',
            expires: Carbon::createFromTimestamp($expiresAt)->toIso8601String(),
        );
    }
}
