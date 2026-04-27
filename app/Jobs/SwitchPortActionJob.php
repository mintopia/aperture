<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SwitchPortActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<string> */
    private const array ALLOWED_ACTIONS = ['shutdown', 'enable'];

    public function __construct(
        public SwitchConfig $switchConfig,
        public string $portId,
        public string $action,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 5, 10];
    }

    public function handle(SwitchServiceFactory $factory): void
    {
        if (! in_array($this->action, self::ALLOWED_ACTIONS, true)) {
            Log::error('Invalid SwitchPortActionJob action', ['action' => $this->action]);

            return;
        }

        $adapter = $factory->make($this->switchConfig);

        match ($this->action) {
            'shutdown' => $adapter->shutdownPort($this->portId),
            'enable' => $adapter->enablePort($this->portId),
        };

        $newAdminStatus = $this->action === 'shutdown' ? 'down' : 'up';

        SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', $this->portId)
            ->update(['admin_status' => $newAdminStatus]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SwitchPortActionJob failed', [
            'switch' => $this->switchConfig->id,
            'port' => $this->portId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
