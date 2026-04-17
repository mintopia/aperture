<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function integrations(): Response
    {
        /** @var array<string, array{name: string, description?: string, capabilities: list<string>, validation?: array<string, string>}> $integrations */
        $integrations = config('integrations', []);

        $services = array_map(function (string $id, array $meta): array {
            $config = IntegrationConfig::getAll($id);
            $latestTest = ConnectionTestLog::latestFor($id);
            $activeCapabilities = CapabilityAssignment::getForIntegration($id);
            $capabilities = array_map(fn (string $cap): array => [
                'name' => $cap,
                'active' => $activeCapabilities->contains($cap),
            ], $meta['capabilities']);

            return [
                'id' => $id,
                'name' => $meta['name'],
                'enabled' => $this->isIntegrationEnabled($config),
                'health' => $latestTest?->success,
                'capabilities' => $capabilities,
            ];
        }, array_keys($integrations), $integrations);

        return Inertia::render('Admin/Settings/Integrations', [
            'services' => $services,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Settings'],
                ['label' => 'Integrations'],
            ],
            'capabilityDescriptions' => [
                'captive-portal' => 'Manages network access by redirecting users to a login page before granting internet access.',
                'firewall' => 'Controls network traffic with rules for allowing or blocking connections.',
                'rate-limiting' => 'Limits bandwidth per user or connection to ensure fair network usage.',
                'dhcp' => 'Assigns IP addresses to devices on the network automatically.',
                'ip-to-mac' => 'Resolves IP addresses to their corresponding MAC (hardware) addresses.',
                'mac-to-port' => 'Maps MAC addresses to the physical switch port they are connected to.',
                'port-bandwidth' => 'Monitors bandwidth usage on individual switch ports over time.',
                'device-list' => 'Provides a list of all discovered network devices and their details.',
                'user-bandwidth' => 'Tracks bandwidth usage per user or IP address.',
                'top-talkers' => 'Identifies the users or devices generating the most network traffic.',
                'aggregate-stats' => 'Provides summary statistics across the entire network.',
                'dns-filtering' => 'Blocks access to malicious or unwanted domains at the DNS level.',
                'authentication' => 'Provides user authentication via OAuth2 or other identity protocols.',
                'sso' => 'Enables single sign-on so users authenticate once for multiple services.',
                'user-info' => 'Retrieves user profile information from an identity provider.',
                'port-errors' => 'Monitors error counters on switch ports to detect physical layer issues.',
                'device-metrics' => 'Collects CPU, memory, and other health metrics from network devices.',
            ],
        ]);
    }

    /**
     * Determine if an integration is enabled.
     * Checks explicit enabled flag first, falls back to endpoint presence.
     *
     * @param  array<string, mixed>  $config
     */
    private function isIntegrationEnabled(array $config): bool
    {
        if (isset($config['enabled'])) {
            return (bool) $config['enabled'];
        }

        return ! empty($config['endpoint'] ?? null);
    }
}
