<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch\Transport;

use App\Models\SwitchConfig;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\SshProxyClientInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SshProxyTransport implements SwitchCommandTransportInterface
{
    public function __construct(
        protected SshProxyClientInterface $proxyClient,
        protected SwitchConfig $switchConfig,
    ) {}

    public function execute(string $command): string
    {
        $outputs = $this->executeMultiple([$command]);

        if (! array_key_exists($command, $outputs)) {
            throw new RuntimeException(sprintf('Missing output for switch command [%s].', $command));
        }

        return $outputs[$command];
    }

    public function executeMultiple(array $commands): array
    {
        $proxyCommands = $this->buildCommands($commands);

        Log::debug('SshProxyTransport: sending commands to proxy', [
            'hostname' => $this->switchConfig->hostname,
            'command_count' => count($commands),
            'proxy_command_count' => count($proxyCommands),
            'commands' => $commands,
            'proxy_commands' => $this->sanitizeCommandsForLog($proxyCommands),
        ]);

        $result = $this->proxyClient->execute(
            $this->switchConfig->hostname,
            $this->switchConfig->username,
            $this->switchConfig->password,
            $proxyCommands,
            $this->switchConfig->port ?? 22,
        );

        if (! $result->success) {
            Log::warning('SshProxyTransport: command execution failed', [
                'hostname' => $this->switchConfig->hostname,
                'error' => $result->error,
                'commands' => $commands,
                'output_count' => count($result->output),
                'outputs' => array_map(fn (CommandOutput $o): array => [
                    'command' => $this->sanitizeCommandForLog($o->command),
                    'output_length' => strlen($o->output),
                    'output_tail' => substr($o->output, -200),
                ], $result->output),
            ]);
            throw new RuntimeException($result->error ?? 'Switch proxy command execution failed.');
        }

        Log::debug('SshProxyTransport: commands executed successfully', [
            'hostname' => $this->switchConfig->hostname,
            'output_count' => count($result->output),
            'outputs' => array_map(fn (CommandOutput $o): array => [
                'command' => $this->sanitizeCommandForLog($o->command),
                'output_length' => strlen($o->output),
            ], $result->output),
        ]);

        return $this->mapOutputs($result, $commands);
    }

    public function isConnected(): bool
    {
        try {
            $this->proxyClient->status();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function disconnect(): void {}

    /**
     * @param  array<int, string>  $commands
     * @return array<int, array{command: string, expect?: string, if?: string}>
     */
    protected function buildCommands(array $commands): array
    {
        $proxyCommands = [];

        $enablePassword = $this->switchConfig->enable_password ?? '';
        $defaultPromptExpectation = $enablePassword !== '' ? '/^.*#\s*$/' : '/^.*[>#]\s*$/';

        if ($enablePassword !== '') {
            // Use "if" conditions so enable commands are skipped on pooled
            // connections that are already in privileged-exec mode.
            $proxyCommands[] = ['command' => 'en', 'if' => '/>\s*$/', 'expect' => '/Password:/'];
            $proxyCommands[] = ['command' => $enablePassword, 'if' => '/Password:/', 'expect' => $defaultPromptExpectation];
        }

        $proxyCommands[] = ['command' => 'terminal length 0', 'expect' => $defaultPromptExpectation];

        foreach ($commands as $command) {
            $proxyCommands[] = [
                'command' => $command,
                'expect' => $this->expectedPromptFor($command, $defaultPromptExpectation),
            ];
        }

        return $proxyCommands;
    }

    /**
     * Sanitize proxy commands for logging — masks passwords.
     *
     * @param  array<int, array{command: string, expect?: string, if?: string}>  $commands
     * @return array<int, array{command: string, expect?: string, if?: string}>
     */
    protected function sanitizeCommandsForLog(array $commands): array
    {
        return array_map(fn (array $cmd): array => [
            'command' => $this->sanitizeCommandForLog($cmd['command']),
            ...array_filter([
                'expect' => $cmd['expect'] ?? null,
                'if' => $cmd['if'] ?? null,
            ]),
        ], $commands);
    }

    /**
     * Sanitize a single command for logging.
     */
    protected function sanitizeCommandForLog(string $command): string
    {
        $enablePassword = $this->switchConfig->enable_password ?? '';
        if ($enablePassword !== '' && $command === $enablePassword) {
            return '****';
        }

        return $command;
    }

    protected function expectedPromptFor(string $command, string $defaultPromptExpectation): string
    {
        return match (true) {
            in_array($command, ['configure terminal', 'conf t'], true) => '/\\(config\\)#\s*$/',
            str_starts_with($command, 'interface ') || str_starts_with($command, 'int ') => '/\\(config-if\\)#\s*$/',
            in_array($command, ['shutdown', 'no shutdown', 'shut', 'no shut'], true) => '/\\(config-if\\)#\s*$/',
            default => $defaultPromptExpectation,
        };
    }

    /**
     * @param  array<int, string>  $commands
     * @return array<string, string>
     */
    protected function mapOutputs(CommandResult $result, array $commands): array
    {
        $outputs = [];

        foreach ($result->output as $output) {
            if (! in_array($output->command, $commands, true)) {
                continue;
            }

            $outputs[$output->command] = $this->sanitizeOutput($output->command, $output->output);
        }

        foreach ($commands as $command) {
            if (! array_key_exists($command, $outputs)) {
                Log::warning('SshProxyTransport: missing output for command', [
                    'hostname' => $this->switchConfig->hostname,
                    'command' => $command,
                    'available_commands' => array_map(
                        fn (CommandOutput $o): string => $this->sanitizeCommandForLog($o->command),
                        $result->output
                    ),
                ]);
                throw new RuntimeException(sprintf('Missing output for switch command [%s].', $command));
            }
        }

        return $outputs;
    }

    protected function sanitizeOutput(string $command, string $response): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $response) ?: [];

        if ($lines !== [] && $this->isEchoedCommandLine($command, $lines[0])) {
            array_shift($lines);
        }

        if ($lines !== [] && $this->isPromptLine($lines[array_key_last($lines)])) {
            array_pop($lines);
        }

        return trim(implode("\r\n", $lines));
    }

    protected function isEchoedCommandLine(string $command, string $line): bool
    {
        return trim($line) === trim($command);
    }

    protected function isPromptLine(string $line): bool
    {
        return preg_match('/^.*[>#]\s*$/', rtrim($line)) === 1;
    }
}
