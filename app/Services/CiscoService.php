<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class CiscoService
{
    protected ?SSH2 $connection = null;

    protected bool $enable = false;

    protected string $name = '';

    public function __construct(
        protected string $hostname,
        protected string $username = '',
        protected string $password = '',
        protected string $enablePassword = '',
        protected int $timeout = 5,
    ) {}

    protected function ssh(): SSH2
    {
        $this->connect();
        assert($this->connection instanceof SSH2);

        return $this->connection;
    }

    public function showInterface(string $interface): string
    {
        $this->connect();
        Log::debug(sprintf('[Cisco] [%s] > sh int %s', $this->hostname, $interface));
        $this->ssh()->write(sprintf('sh int %s%s', $interface, PHP_EOL));
        $result = explode("\r\n", (string) $this->ssh()->read($this->name.'>
'));

        return implode("\r\n", array_slice($result, 1, -1));
    }

    public function showInterfaceStatus(): string
    {
        $this->connect();
        Log::debug(sprintf('[Cisco] [%s] > sh int status', $this->hostname));
        $this->ssh()->write(sprintf('sh int status%s', PHP_EOL));
        $result = explode("\r\n", (string) $this->ssh()->read($this->name.'>
'));

        return implode("\r\n", array_slice($result, 1, -1));
    }

    public function showMacAddressTable(): string
    {
        $this->connect();
        Log::debug(sprintf('[Cisco] [%s] > sh mac address-table', $this->hostname));
        $this->ssh()->write(sprintf('sh mac address-table%s', PHP_EOL));
        $result = explode("\r\n", (string) $this->ssh()->read($this->name.'>
'));

        return implode("\r\n", array_slice($result, 1, -1));
    }

    public function showInterfaceConfig(string $interface): string
    {
        $this->connect();
        $this->enable();
        Log::debug(sprintf('[Cisco] [%s] > sh run int %s', $this->hostname, $interface));
        $this->ssh()->write(sprintf('sh run int %s%s', $interface, PHP_EOL));
        $result = explode("\r\n", (string) $this->ssh()->read($this->name.'#
'));

        return implode("\r\n", array_slice($result, 5, -2));
    }

    public function shutInterface(string $interface, bool $write = false): void
    {
        $this->connect();
        $this->enable();
        $this->configure();

        Log::debug(sprintf('[Cisco] [%s] > int %s', $this->hostname, $interface));
        $this->ssh()->write(sprintf('int %s%s', $interface, PHP_EOL));
        $this->ssh()->read($this->name.'(config-if)#');
        Log::debug(sprintf('[Cisco] [%s] > shut', $this->hostname));
        $this->ssh()->write("shut\n");
        $this->ssh()->read($this->name.'(config-if)#
');
        Log::debug(sprintf('[Cisco] [%s] > end', $this->hostname));
        $this->ssh()->write("end\n");
        $this->ssh()->read($this->name.'#');
        Log::debug(sprintf('[Cisco] [%s] > wr', $this->hostname));
        $this->ssh()->write("wr\n");
        $this->ssh()->read($this->name.'#');
    }

    public function unshutInterface(string $interface, bool $write = false): void
    {
        $this->connect();
        $this->enable();
        $this->configure();

        Log::debug(sprintf('[Cisco] [%s] > int %s', $this->hostname, $interface));
        $this->ssh()->write(sprintf('int %s%s', $interface, PHP_EOL));
        $this->ssh()->read($this->name.'(config-if)#');
        Log::debug(sprintf('[Cisco] [%s] > no shut', $this->hostname));
        $this->ssh()->write("no shut\n");
        $this->ssh()->read($this->name.'(config-if)#');
        Log::debug(sprintf('[Cisco] [%s] > end', $this->hostname));
        $this->ssh()->write("end\n");
        $this->ssh()->read($this->name.'#');
        Log::debug(sprintf('[Cisco] [%s] > wr', $this->hostname));
        $this->ssh()->write("wr\n");
        $this->ssh()->read($this->name.'#');
    }

    protected function configure(): void
    {
        $this->connect();
        $this->enable();
        Log::debug(sprintf('[Cisco] [%s] > conf t', $this->hostname));
        $this->ssh()->write("conf t\n");
        $this->ssh()->read("(config)#\n");
    }

    protected function enable(): void
    {
        if ($this->enable) {
            return;
        }

        $this->connect();

        Log::debug(sprintf('[Cisco] [%s] > en', $this->hostname));
        $this->ssh()->write("en\n");
        $this->ssh()->read('Password:');
        $this->ssh()->write($this->enablePassword.PHP_EOL);
        $this->ssh()->read($this->name.'#');

        $this->enable = true;
    }

    protected function connect(): void
    {
        if ($this->connection instanceof SSH2) {
            return;
        }

        Log::debug(sprintf('[Cisco] [%s] Connecting with SSH', $this->hostname));
        $this->connection = $this->createSshConnection();
        if (! $this->connection->login($this->username, $this->password)) {
            throw new Exception('Unable to authenticate with switch');
        }

        $this->connection->setTimeout($this->timeout);
        $this->name = substr(trim((string) $this->ssh()->read()), 0, -1);
        Log::debug(sprintf('[Cisco] [%s] > terminal length 0', $this->hostname));
        $this->ssh()->write("terminal length 0\n");
        $this->ssh()->read($this->name.'>');
    }

    protected function createSshConnection(): SSH2
    {
        return new SSH2($this->hostname, 22, 2);
    }
}
