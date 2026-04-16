<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\PiHole\PiHoleApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntegrationController extends Controller
{
    /**
     * @return array<string, array{
     *     name: string,
     *     description?: string,
     *     capabilities: list<string>,
     *     fields?: array<string, array{type: string, label: string, placeholder?: string, help?: string, required?: bool, remote_url?: string, remote_label?: string, remote_value?: string, options?: array<string, string>}>,
     *     validation?: array<string, string>
     * }>
     */
    private function integrations(): array
    {
        /** @var array<string, array{name: string, description?: string, capabilities: list<string>, fields?: array<string, array{type: string, label: string, placeholder?: string, help?: string, required?: bool, remote_url?: string, remote_label?: string, remote_value?: string, options?: array<string, string>}>, validation?: array<string, string>}> $integrations */
        $integrations = config('integrations', []);

        return $integrations;
    }

    public function show(string $service): Response
    {
        $integrations = $this->integrations();
        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException('Unknown integration: '.$service);
        }

        $meta = $integrations[$service];
        $config = IntegrationConfig::getAll($service);
        $activeCapabilities = CapabilityAssignment::getForIntegration($service);
        $logs = ConnectionTestLog::recentFor($service, 20);
        $latestTest = $logs->first();
        $capabilities = $meta['capabilities'];

        return Inertia::render('Admin/Settings/IntegrationShow', [
            'service' => [
                'id' => $service,
                'name' => $meta['name'],
                'description' => $meta['description'] ?? '',
                'config' => collect($meta['fields'] ?? [])->mapWithKeys(fn (array $field, string $key): array => [
                    $key => $config[$key] ?? '',
                ])->all(),
                'fields' => collect($meta['fields'] ?? [])->map(fn (array $field, string $key): array => array_filter([
                    'key' => $key,
                    'type' => $field['type'],
                    'label' => $field['label'],
                    'placeholder' => $field['placeholder'] ?? '',
                    'help' => $field['help'] ?? '',
                    'required' => $field['required'] ?? false,
                    'remote_url' => $field['remote_url'] ?? null,
                    'remote_label' => $field['remote_label'] ?? null,
                    'remote_value' => $field['remote_value'] ?? null,
                    'options' => $field['options'] ?? null,
                ], fn (mixed $v): bool => $v !== null))->values()->all(),
                'capabilities' => collect($capabilities)->map(fn (string $cap): array => [
                    'name' => $cap,
                    'active' => $activeCapabilities->contains($cap),
                ])->values()->all(),
                'health' => $latestTest?->success,
                'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                    'id' => $log->id,
                    'success' => $log->success,
                    'message' => $log->message,
                    'request_method' => $log->request_method,
                    'request_url' => $log->request_url,
                    'response_status' => $log->response_status,
                    'response_data' => $log->response_data,
                    'tested_at' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
        ]);
    }

    public function update(Request $request, string $service): RedirectResponse
    {
        $integrations = $this->integrations();
        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException('Unknown integration: '.$service);
        }

        $validationRules = $integrations[$service]['validation'] ?? [];
        $rules = ['config' => 'required|array'];
        foreach ($validationRules as $field => $rule) {
            $rules['config.'.$field] = $rule;
        }

        $validated = $request->validate($rules);

        foreach ($validated['config'] as $key => $value) {
            if (! array_key_exists($key, $validationRules)) {
                continue;
            }

            $encrypted = in_array($key, IntegrationConfig::ENCRYPTED_KEYS, true);
            IntegrationConfig::setValue($service, $key, $value, $encrypted);
        }

        return back()->with('success', 'Integration settings updated.');
    }

    public function toggleCapability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => 'required|string',
            'integration' => 'required|string',
            'active' => 'required|boolean',
        ]);

        $integrations = $this->integrations();
        $capabilities = $integrations[$validated['integration']]['capabilities'] ?? [];

        if (! in_array($validated['capability'], $capabilities, true)) {
            return response()->json([
                'message' => sprintf(
                    'Integration %s does not support capability %s.',
                    $validated['integration'],
                    $validated['capability']
                ),
            ], 422);
        }

        if ($validated['active']) {
            CapabilityAssignment::assign($validated['capability'], $validated['integration']);
        } else {
            CapabilityAssignment::unassign($validated['capability']);
        }

        return response()->json(['success' => true]);
    }

    public function healthLog(string $service): JsonResponse
    {
        $integrations = $this->integrations();
        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException('Unknown integration: '.$service);
        }

        $logs = ConnectionTestLog::recentFor($service, 20);

        return response()->json([
            'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                'id' => $log->id,
                'success' => $log->success,
                'message' => $log->message,
                'request_method' => $log->request_method,
                'request_url' => $log->request_url,
                'response_status' => $log->response_status,
                'response_data' => $log->response_data,
                'tested_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function opnsenseShaperRules(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('opnsense', $request);

        return response()->json(OpnSenseApiService::getShaperRules($config));
    }

    public function opnsenseZones(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('opnsense', $request);

        return response()->json(OpnSenseApiService::getZones($config));
    }

    public function piholeGroups(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('pihole', $request);

        return response()->json(PiHoleApiService::getGroups($config));
    }

    /**
     * Merge saved DB config with non-empty request values (request takes precedence).
     *
     * @return array<string, mixed>
     */
    private function mergeConfig(string $integration, Request $request): array
    {
        $dbConfig = IntegrationConfig::getAll($integration);

        return array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
    }
}
