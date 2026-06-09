<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ToggleCapabilityRequest;
use App\Models\AuditLog;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\IntegrationConfigMerger;
use App\Services\PiHole\PiHoleApiService;
use App\Services\Seatpicker\SeatpickerApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntegrationController extends Controller
{
    public function __construct(private IntegrationConfigMerger $configMerger) {}

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

    /**
     * Resolve a known integration by service slug, or throw a 404.
     *
     * @return array{name: string, description?: string, capabilities: list<string>, fields?: array<string, mixed>, validation?: array<string, string>}
     */
    private function resolveIntegration(string $service): array
    {
        $integrations = $this->integrations();

        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException('Unknown integration: '.$service);
        }

        return $integrations[$service];
    }

    /**
     * Serialize a collection of ConnectionTestLog models to an array for API/Inertia responses.
     *
     * @param  Collection<int, ConnectionTestLog>  $logs
     * @return list<array{id: int, success: bool, message: ?string, request_method: ?string, request_url: ?string, response_status: ?int, response_data: ?string, tested_at: ?string}>
     */
    private function serializeLogs(Collection $logs): array
    {
        return array_values($logs->map(fn (ConnectionTestLog $log): array => [
            'id' => $log->id,
            'success' => $log->success,
            'message' => $log->message,
            'request_method' => $log->request_method,
            'request_url' => $log->request_url,
            'response_status' => $log->response_status,
            'response_data' => $log->response_data,
            'tested_at' => $log->created_at?->toIso8601String(),
        ])->all());
    }

    public function show(string $service): Response
    {
        $meta = $this->resolveIntegration($service);
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
                    'options' => $this->resolveFieldOptions($field['options'] ?? null),
                ], fn (mixed $v): bool => $v !== null))->values()->all(),
                'capabilities' => collect($capabilities)->map(fn (string $cap): array => [
                    'name' => $cap,
                    'active' => $activeCapabilities->contains($cap),
                ])->values()->all(),
                'health' => $latestTest?->success,
                'logs' => $this->serializeLogs($logs),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Services'],
                ['label' => 'Integrations', 'href' => route('admin.settings.integrations')],
                ['label' => $meta['name']],
            ],
        ]);
    }

    public function update(Request $request, string $service): RedirectResponse
    {
        $meta = $this->resolveIntegration($service);

        $validationRules = $meta['validation'] ?? [];
        $rules = ['config' => 'required|array'];
        foreach ($validationRules as $field => $rule) {
            $rules['config.'.$field] = $rule;
        }

        $validated = $request->validate($rules);

        /** @var IntegrationConfig|null $lastConfig */
        $lastConfig = null;
        foreach ($validated['config'] as $key => $value) {
            if (! array_key_exists($key, $validationRules)) {
                continue;
            }

            $value = $this->castConfigValue($value, $validationRules[$key]);

            $encrypted = in_array($key, IntegrationConfig::encryptedKeys(), true);
            IntegrationConfig::setValue($service, $key, $value, $encrypted);
            $lastConfig = IntegrationConfig::where('integration', $service)->where('key', $key)->first();
        }

        if ($lastConfig) {
            AuditLog::record(
                action: 'integration.updated',
                subject: $lastConfig,
                process: 'admin',
                metadata: ['ip' => $request->getClientIp(), 'service' => $service],
            );
        }

        return back()->with('success', 'Integration settings updated.');
    }

    /**
     * Cast a config value to the appropriate PHP type based on its validation rule.
     *
     * HTML form inputs always submit strings; this ensures values like "integer"
     * fields are stored with their correct PHP type.
     */
    private function castConfigValue(mixed $value, string $rule): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $parts = explode('|', $rule);

        if (in_array('integer', $parts, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $parts, true)) {
            return is_numeric($value) ? $value + 0 : $value;
        }

        return $value;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveFieldOptions(mixed $options): ?array
    {
        if (is_array($options) || $options === null) {
            return $options;
        }

        if ($options === 'switch_configs') {
            return ['' => 'None'] + SwitchConfig::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->map(fn (string $name): string => $name)
                ->all();
        }

        return null;
    }

    public function toggleCapability(ToggleCapabilityRequest $request): JsonResponse
    {
        $validated = $request->validated();

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
            CapabilityAssignment::where('capability', $validated['capability'])
                ->where('integration', $validated['integration'])
                ->delete();
        }

        return response()->json(['success' => true]);
    }

    public function healthLog(string $service): JsonResponse
    {
        $this->resolveIntegration($service);

        $logs = ConnectionTestLog::recentFor($service, 20);

        return response()->json(['logs' => $this->serializeLogs($logs)]);
    }

    public function opnsenseShaperRules(Request $request): JsonResponse
    {
        $service = new OpnSenseApiService($this->configMerger->merge('opnsense', $request));

        return response()->json($service->getShaperRules());
    }

    public function opnsenseZones(Request $request): JsonResponse
    {
        $service = new OpnSenseApiService($this->configMerger->merge('opnsense', $request));

        return response()->json($service->getZones());
    }

    public function piholeGroups(Request $request): JsonResponse
    {
        return response()->json(PiHoleApiService::getGroups($this->configMerger->merge('pihole', $request)));
    }

    public function seatpickerEvents(Request $request): JsonResponse
    {
        return response()->json(SeatpickerApiService::getEvents($this->configMerger->merge('seatpicker', $request)));
    }
}
