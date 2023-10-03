<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class CiscoService
{
    protected ?SSH2 $connection = null;
    protected $enable = false;
    protected string $name = '';

    public function __construct(protected string $hostname)
    {
    }

    public function showInterface(string $interface): string
    {
        $this->connect();
        Log::debug("[Cisco] [{$this->hostname}] > sh int {$interface}");
        $this->connection->write("sh int {$interface}\n");
        $result = explode("\r\n", $this->connection->read("{$this->name}>\n"));
        return implode("\r\n", array_slice($result, 1, -1));
    }

    public function showInterfaceConfig(string $interface): string
    {
        $this->connect();
        $this->enable();
        Log::debug("[Cisco] [{$this->hostname}] > sh run int {$interface}");
        $this->connection->write("sh run int {$interface}\n");
        $result = explode("\r\n", $this->connection->read("{$this->name}#\n"));
        return implode("\r\n", array_slice($result, 5, -2));
    }

    public function shutInterface(string $interface, $write = false)
    {
        $this->connect();
        $this->enable();
        $this->configure();

        Log::debug("[Cisco] [{$this->hostname}] > int {$interface}");
        $this->connection->write("int {$interface}\n");
        $this->connection->read("{$this->name}(config-if)#");
        Log::debug("[Cisco] [{$this->hostname}] > shut");
        $this->connection->write("shut\n");
        $this->connection->read("{$this->name}(config-if)#\n");
        Log::debug("[Cisco] [{$this->hostname}] > end");
        $this->connection->write("end\n");
        $this->connection->read("{$this->name}#");
        Log::debug("[Cisco] [{$this->hostname}] > wr");
        $this->connection->write("wr\n");
        $this->connection->read("{$this->name}#");
    }

    public function unshutInterface(string $interface, bool $write = false)
    {
        $this->connect();
        $this->enable();
        $this->configure();

        Log::debug("[Cisco] [{$this->hostname}] > int {$interface}");
        $this->connection->write("int {$interface}\n");
        $this->connection->read("{$this->name}(config-if)#");
        Log::debug("[Cisco] [{$this->hostname}] > no shut");
        $this->connection->write("no shut\n");
        $this->connection->read("{$this->name}(config-if)#");
        Log::debug("[Cisco] [{$this->hostname}] > end");
        $this->connection->write("end\n");
        $this->connection->read("{$this->name}#");
        Log::debug("[Cisco] [{$this->hostname}] > wr");
        $this->connection->write("wr\n");
        $this->connection->read("{$this->name}#");
    }

    protected function configure(): void
    {
        $this->connect();
        $this->enable();
        Log::debug("[Cisco] [{$this->hostname}] > conf t");
        $this->connection->write("conf t\n");
        $this->connection->read("(config)#\n");
    }

    protected function enable(): void
    {
        if ($this->enable) {
            return;
        }

        $this->connect();
        $enPassword = config('aperture.cisco.enablePassword');

        Log::debug("[Cisco] [{$this->hostname}] > en");
        $this->connection->write("en\n");
        $this->connection->read("Password:");
        $this->connection->write("{$enPassword}\n");
        $this->connection->read("{$this->name}#");

        $this->enable = true;
    }

    protected function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }
        Log::debug("[Cisco] [{$this->hostname}] Connecting with SSH");
        $this->connection = new SSH2($this->hostname, 22, 2);
        if (!$this->connection->login(config('aperture.cisco.username'), config('aperture.cisco.password'))) {
            throw new \Exception('Unable to authenticate with switch');
        }
        $this->connection->setTimeout(1);
        $this->name = substr(trim($this->connection->read()), 0, -1);
        Log::debug("[Cisco] [{$this->hostname}] > terminal length 0");
        $this->connection->write("terminal length 0\n");
        $this->connection->read("{$this->name}>");
    }
}
