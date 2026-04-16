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
