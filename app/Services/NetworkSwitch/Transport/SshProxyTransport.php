<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch\Transport;

use App\Exceptions\SwitchHostKeyMismatchException;
use App\Models\SwitchConfig;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\Matcher;
use App\Services\SshProxy\SwitchProxyExecutor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SshProxyTransport implements SwitchCommandTransportInterface
{
    private const string USER_PROMPT = '^{prompt}>\s*$';

    private const string PRIVILEGED_PROMPT = '^{prompt}(\([^)]*\))?#\s*$';

    private const string ANY_PROMPT = '^{prompt}(\([^)]*\))?[>#]\s*$';

    private const string CONFIG_PROMPT = '^{prompt}\(config\)#\s*$';

    private const string CONFIG_IF_PROMPT = '^{prompt}\(config-if\)#\s*$';

    public function __construct(
        protected SshProxyClientInterface $proxyClient,
        protected SwitchConfig $switchConfig,
        protected string $channel = 'commands',
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
            'proxy_commands' => $this->redactCommandsForLog($proxyCommands),
        ]);

        $result = (new SwitchProxyExecutor($this->proxyClient))->execute($this->switchConfig, $proxyCommands, $this->channel);

        if (! $result->success) {
            Log::warning('SshProxyTransport: command execution failed', [
                'hostname' => $this->switchConfig->hostname,
                'error' => $result->error,
                'commands' => $commands,
                'output_count' => count($result->output),
                'outputs' => array_map(fn (CommandOutput $o): array => [
                    'command' => $this->redactOutputCommandForLog($o->command),
                    'output_length' => strlen($o->output),
                    'output_tail' => substr($o->output, -200),
                ], $result->output),
            ]);
            if ($result->errorCode === CommandResult::HOST_KEY_MISMATCH) {
                throw new SwitchHostKeyMismatchException($result->failureMessage());
            }

            throw new RuntimeException($result->failureMessage());
        }

        Log::debug('SshProxyTransport: commands executed successfully', [
            'hostname' => $this->switchConfig->hostname,
            'output_count' => count($result->output),
            'outputs' => array_map(fn (CommandOutput $o): array => [
                'command' => $this->redactOutputCommandForLog($o->command),
                'output_length' => strlen($o->output),
            ], $result->output),
        ]);

        return $this->mapOutputs($result, $commands);
    }

    public function disconnect(): void {}

    /**
     * @param  array<int, string>  $commands
     * @return array<int, array{command: string, sensitive?: true, if?: array{type: 'literal'|'regex', value: string}, expect: array{type: 'literal'|'regex', value: string}}>
     */
    protected function buildCommands(array $commands): array
    {
        $enablePassword = $this->switchConfig->enable_password ?? '';
        $promptExpectation = Matcher::regex($enablePassword !== '' ? self::PRIVILEGED_PROMPT : self::ANY_PROMPT);

        $proxyCommands = $enablePassword !== '' ? $this->enableStepsSkippedOnPooledConnections($enablePassword, $promptExpectation) : [];
        $proxyCommands[] = ['command' => 'terminal length 0', 'expect' => $promptExpectation];

        foreach ($commands as $command) {
            $proxyCommands[] = [
                'command' => $command,
                'expect' => $this->expectedPromptFor($command, $promptExpectation),
            ];
        }

        return $proxyCommands;
    }

    /**
     * @param  array{type: 'literal'|'regex', value: string}  $promptExpectation
     * @return array<int, array{command: string, sensitive?: true, if: array{type: 'literal'|'regex', value: string}, expect: array{type: 'literal'|'regex', value: string}}>
     */
    protected function enableStepsSkippedOnPooledConnections(string $enablePassword, array $promptExpectation): array
    {
        return [
            ['command' => 'en', 'if' => Matcher::regex(self::USER_PROMPT), 'expect' => Matcher::literal('Password:')],
            ['command' => $enablePassword, 'sensitive' => true, 'if' => Matcher::literal('Password:'), 'expect' => $promptExpectation],
        ];
    }

    /**
     * @param  array<int, array{command: string, sensitive?: true, if?: array{type: 'literal'|'regex', value: string}, expect: array{type: 'literal'|'regex', value: string}}>  $commands
     * @return array<int, array<string, mixed>>
     */
    protected function redactCommandsForLog(array $commands): array
    {
        return array_map(fn (array $cmd): array => [
            ...$cmd,
            'command' => ($cmd['sensitive'] ?? false) ? '****' : $cmd['command'],
        ], $commands);
    }

    protected function redactOutputCommandForLog(string $command): string
    {
        $enablePassword = $this->switchConfig->enable_password ?? '';

        return $enablePassword !== '' && $command === $enablePassword ? '****' : $command;
    }

    /**
     * @param  array{type: 'literal'|'regex', value: string}  $defaultPromptExpectation
     * @return array{type: 'literal'|'regex', value: string}
     */
    protected function expectedPromptFor(string $command, array $defaultPromptExpectation): array
    {
        return match (true) {
            in_array($command, ['configure terminal', 'conf t'], true) => Matcher::regex(self::CONFIG_PROMPT),
            str_starts_with($command, 'interface ') || str_starts_with($command, 'int ') => Matcher::regex(self::CONFIG_IF_PROMPT),
            in_array($command, ['shutdown', 'no shutdown', 'shut', 'no shut'], true) => Matcher::regex(self::CONFIG_IF_PROMPT),
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
                        fn (CommandOutput $o): string => $this->redactOutputCommandForLog($o->command),
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
        return preg_match('/^[^\s()#>]+(\([^)]*\))?[>#]$/', rtrim($line)) === 1;
    }
}
