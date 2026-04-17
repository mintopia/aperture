<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch\Transport;

use Exception;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use RuntimeException;

class DirectSshTransport implements SwitchCommandTransportInterface
{
    protected ?SSH2 $connection = null;

    protected bool $enableMode = false;

    protected string $hostnamePrompt = '';

    protected string $prompt = '';

    public function __construct(
        protected string $hostname,
        protected string $username,
        protected string $password,
        protected string $enablePassword,
        protected int $port = 22,
        protected int $timeout = 5,
    ) {}

    public function execute(string $command): string
    {
        return $this->executeMultiple([$command])[$command];
    }

    public function executeMultiple(array $commands): array
    {
        $this->connect();

        $outputs = [];

        foreach ($commands as $command) {
            $expect = $this->expectedPromptFor($command);
            Log::debug(sprintf('[Cisco] [%s] > %s', $this->hostname, $command));
            $this->ssh()->write($command.PHP_EOL);
            $response = $this->readUntilPrompt($expect);
            $outputs[$command] = $this->sanitizeOutput($response);
            $this->updatePromptState($command);
        }

        return $outputs;
    }

    public function isConnected(): bool
    {
        return $this->connection instanceof SSH2;
    }

    public function disconnect(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;
        $this->enableMode = false;
        $this->hostnamePrompt = '';
        $this->prompt = '';
    }

    protected function ssh(): SSH2
    {
        $this->connect();
        assert($this->connection instanceof SSH2);

        return $this->connection;
    }

    protected function connect(): void
    {
        if ($this->connection instanceof SSH2) {
            return;
        }

        Log::debug(sprintf('[Cisco] [%s] Connecting with SSH', $this->hostname));
        $connection = $this->createSshConnection();

        if (! $connection->login($this->username, $this->password)) {
            throw new Exception('Unable to authenticate with switch');
        }

        $this->connection = $connection;
        $this->connection->setTimeout($this->timeout);

        $this->hostnamePrompt = rtrim(trim($this->readUntilPrompt('initial prompt')), ">\r\n#");
        $this->prompt = $this->hostnamePrompt.'>';

        Log::debug(sprintf('[Cisco] [%s] > terminal length 0', $this->hostname));
        $this->ssh()->write("terminal length 0\n");
        $this->readUntilPrompt($this->prompt);

        $this->enterEnableMode();
    }

    protected function enterEnableMode(): void
    {
        if ($this->enableMode || $this->enablePassword === '') {
            return;
        }

        Log::debug(sprintf('[Cisco] [%s] > en', $this->hostname));
        $this->ssh()->write("en\n");
        $this->readUntilPrompt('Password:');
        $this->ssh()->write($this->enablePassword.PHP_EOL);
        $this->readUntilPrompt($this->hostnamePrompt.'#');

        $this->enableMode = true;
        $this->prompt = $this->hostnamePrompt.'#';
    }

    protected function expectedPromptFor(string $command): string
    {
        return match (true) {
            $this->isConfigureTerminalCommand($command) => '(config)#',
            $this->isInterfaceCommand($command), $this->isInterfaceConfigCommand($command) => '(config-if)#',
            $this->isEndCommand($command) => $this->hostnamePrompt.'#',
            default => $this->prompt."\n",
        };
    }

    protected function updatePromptState(string $command): void
    {
        $this->prompt = match (true) {
            $this->isConfigureTerminalCommand($command) => '(config)#',
            $this->isInterfaceCommand($command), $this->isInterfaceConfigCommand($command) => '(config-if)#',
            $this->isEndCommand($command) => $this->hostnamePrompt.'#',
            default => $this->prompt,
        };
    }

    protected function isConfigureTerminalCommand(string $command): bool
    {
        return in_array($command, ['configure terminal', 'conf t'], true);
    }

    protected function isInterfaceCommand(string $command): bool
    {
        return str_starts_with($command, 'interface ') || str_starts_with($command, 'int ');
    }

    protected function isInterfaceConfigCommand(string $command): bool
    {
        return in_array($command, ['shutdown', 'no shutdown', 'shut', 'no shut'], true);
    }

    protected function isEndCommand(string $command): bool
    {
        return in_array($command, ['end', 'write memory', 'wr'], true);
    }

    protected function sanitizeOutput(string $response): string
    {
        $lines = explode("\r\n", $response);

        return trim(implode("\r\n", array_slice($lines, 1, -1)));
    }

    protected function readUntilPrompt(string $prompt): string
    {
        $response = $prompt === 'initial prompt'
            ? $this->ssh()->read()
            : $this->ssh()->read($prompt);

        if (! is_string($response)) {
            throw new RuntimeException(sprintf('SSH read timed out waiting for [%s] on %s', $prompt, $this->hostname));
        }

        return $response;
    }

    protected function createSshConnection(): SSH2
    {
        return new SSH2($this->hostname, $this->port, $this->timeout);
    }
}
