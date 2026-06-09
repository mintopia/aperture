<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\SwitchConfig;
use App\Services\Interfaces\TestableIntegration;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\TestConnectionResult;
use Throwable;

class CiscoTester implements TestableIntegration
{
    public function __construct(private SwitchServiceFactory $factory) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $switchId = $config['switch_id'] ?? null;

        if ($switchId === null || $switchId === '') {
            return new TestConnectionResult(
                success: false,
                message: 'No switch configured. Select a DHCP switch in the integration settings.',
            );
        }

        $switchConfig = SwitchConfig::find((int) $switchId);

        if ($switchConfig === null) {
            return new TestConnectionResult(
                success: false,
                message: 'Configured switch not found (ID: '.$switchId.').',
            );
        }

        try {
            $transport = $this->factory->createTransport($switchConfig);
            $results = $transport->executeMultiple(['show ip dhcp pool']);
            $transport->disconnect();

            $output = $results['show ip dhcp pool'] ?? '';

            if ($output === '' || str_contains($output, '% Invalid input')) {
                return new TestConnectionResult(
                    success: false,
                    message: 'Connected to '.$switchConfig->hostname.' but DHCP pool query failed.',
                    requestMethod: 'SSH',
                    requestUrl: $switchConfig->hostname,
                    responseBody: $output,
                    output: $output,
                );
            }

            return new TestConnectionResult(
                success: true,
                message: 'Connected to '.$switchConfig->hostname.' — DHCP pools accessible.',
                requestMethod: 'SSH',
                requestUrl: $switchConfig->hostname,
                responseBody: $output,
                output: $output,
            );
        } catch (Throwable $e) {
            return new TestConnectionResult(
                success: false,
                message: 'Connection failed: '.$e->getMessage(),
                requestMethod: 'SSH',
                requestUrl: $switchConfig->hostname ?? 'unknown',
            );
        }
    }
}
