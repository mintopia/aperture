<?php

namespace App\Console\Commands;

use App\Services\SshProxy\CommandExecutor;
use App\Services\SshProxy\RequestHandler;
use App\Services\SshProxy\SshConnectionPool;
use Illuminate\Console\Command;

class SshProxyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aperture:ssh-proxy';

    /**
     * @var string
     */
    protected $description = 'Start the SSH REST proxy server';

    public function handle(): int
    {
        /** @var array{enabled: bool, host: string, port: int, api_key: string|null, idle_timeout_seconds: int, sweep_interval_seconds: int, command_timeout_seconds: int, read_timeout_seconds: int} $config */
        $config = config('aperture.ssh_proxy');

        if (! $config['enabled']) {
            $this->error('SSH proxy is not enabled. Set APERTURE_SSH_PROXY_ENABLED=true');

            return Command::FAILURE;
        }

        $pool = new SshConnectionPool($config['idle_timeout_seconds']);
        $executor = new CommandExecutor($config['read_timeout_seconds'], $config['command_timeout_seconds']);
        $handler = new RequestHandler($pool, $executor, (string) $config['api_key']);

        $address = sprintf('tcp://%s:%d', $config['host'], $config['port']);
        $this->info('Starting SSH proxy server on '.$address);

        $context = stream_context_create(['socket' => ['backlog' => 10]]);
        $server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($server === false) {
            $this->error(sprintf('Failed to start server: %s (%s)', $errstr, $errno));

            return Command::FAILURE;
        }

        // @codeCoverageIgnoreStart
        stream_set_blocking($server, false);
        $lastSweep = time();

        $this->info('SSH proxy server is running. Press Ctrl+C to stop.');

        while (true) { // @phpstan-ignore while.alwaysTrue
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                $this->handleClient($client, $handler);
            }

            // Periodic sweep
            if ((time() - $lastSweep) >= $config['sweep_interval_seconds']) {
                $removed = $pool->sweepIdle();
                if ($removed > 0) {
                    $this->line(sprintf('Swept %d idle connections', $removed));
                }

                $lastSweep = time();
            }

            usleep(10000); // 10ms
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param  resource  $client
     */
    protected function handleClient($client, RequestHandler $handler): void
    {
        $raw = '';
        while (($data = fread($client, 8192)) !== false && $data !== '') {
            $raw .= $data;
            if (str_contains($raw, "\r\n\r\n")) {
                break;
            }
        }

        // Read body if Content-Length is present
        // @codeCoverageIgnoreStart
        if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $m)) {
            $headerEnd = strpos($raw, "\r\n\r\n");
            if ($headerEnd !== false) {
                $bodyStart = $headerEnd + 4;
                $contentLength = (int) $m[1];
                $bodyRead = strlen($raw) - $bodyStart;
                while ($bodyRead < $contentLength) {
                    $remaining = $contentLength - $bodyRead;
                    $data = fread($client, max(1, $remaining));
                    if ($data === false || $data === '') {
                        break;
                    }

                    $raw .= $data;
                    $bodyRead += strlen($data);
                }
            }
        }
        // @codeCoverageIgnoreEnd

        // Parse HTTP request
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerSection = $parts[0];
        $body = $parts[1] ?? '';

        $lines = explode("\r\n", $headerSection);
        $requestLine = $lines[0];
        $requestParts = explode(' ', $requestLine);
        $method = $requestParts[0];
        $path = $requestParts[1] ?? '';

        $headers = [];
        $lineCount = count($lines);
        for ($i = 1; $i < $lineCount; $i++) {
            $colonPos = strpos($lines[$i], ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($lines[$i], 0, $colonPos)));
                $value = trim(substr($lines[$i], $colonPos + 1));
                $headers[$key] = $value;
            }
        }

        $result = $handler->handle($method, $path, $headers, $body);

        $responseBody = json_encode($result['body']);
        $httpResponse = sprintf(
            "HTTP/1.1 %d OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $result['status'],
            strlen((string) $responseBody),
            $responseBody,
        );

        fwrite($client, $httpResponse);
        fclose($client);
    }
}
