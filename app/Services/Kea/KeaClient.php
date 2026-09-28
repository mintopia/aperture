<?php

declare(strict_types=1);

namespace App\Services\Kea;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class KeaClient
{
    private const RESULT_SUCCESS = 0;

    /**
     * Kea result code meaning the command succeeded but returned nothing —
     * treated as a non-error, empty result.
     */
    private const RESULT_EMPTY = 3;

    public function __construct(
        private string $endpoint,
        private ?string $username = null,
        private ?string $password = null,
        private bool $verifySsl = true,
        private string $service = 'dhcp4',
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    /**
     * Send a command to this client's service and return Kea's response entry.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function sendCommand(string $command, array $arguments = []): array
    {
        $payload = [
            'command' => $command,
            'service' => [$this->service],
        ];

        if ($arguments !== []) {
            $payload['arguments'] = $arguments;
        }

        $request = Http::withOptions(['verify' => $this->verifySsl])->timeout(15);

        if ($this->username !== null && $this->password !== null) {
            $request = $request->withBasicAuth($this->username, $this->password);
        }

        $response = $request->post($this->endpoint, $payload);

        $response->throw();

        $body = $response->json();

        if (! is_array($body) || ! is_array($body[0] ?? null)) {
            throw new RuntimeException('Kea API returned an unexpected response shape.');
        }

        /** @var array<string, mixed> $entry */
        $entry = $body[0];

        $result = (int) ($entry['result'] ?? -1);

        if ($result === self::RESULT_SUCCESS || $result === self::RESULT_EMPTY) {
            return $entry;
        }

        throw new RuntimeException((string) ($entry['text'] ?? 'Kea command failed.'));
    }

    /**
     * @return list<string>
     */
    public function listCommands(): array
    {
        $entry = $this->sendCommand('list-commands');

        /** @var mixed $arguments */
        $arguments = $entry['arguments'] ?? [];

        if (! is_array($arguments)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $command): string => (string) $command, $arguments));
    }
}
