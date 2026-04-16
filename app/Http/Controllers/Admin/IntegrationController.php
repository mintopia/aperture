<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class IntegrationController extends Controller
{
    /**
     * @return array<string, array{
     *     name: string,
     *     description?: string,
     *     capabilities: list<string>,
     *     fields?: array<string, array{type: string, label: string, placeholder?: string, help?: string, required?: bool}>,
     *     validation?: array<string, string>
     * }>
     */
    private function integrations(): array
    {
        /** @var array<string, array{name: string, description?: string, capabilities: list<string>, fields?: array<string, array{type: string, label: string, placeholder?: string, help?: string, required?: bool}>, validation?: array<string, string>}> $integrations */
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
                'response_data' => $log->response_data,
                'tested_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function opnsenseShaperRules(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('opnsense');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));

            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $key = $config['key'] ?? '';
            $secret = $config['secret'] ?? '';
            $verifySsl = (bool) ($config['verify_ssl'] ?? true);

            if ($endpoint === '') {
                return response()->json([
                    'rules' => [],
                    'error' => 'OPNsense endpoint is not configured.',
                ]);
            }

            if ($key === '' || $secret === '') {
                return response()->json([
                    'rules' => [],
                    'error' => 'OPNsense API key and secret are required.',
                ]);
            }

            $response = Http::withOptions(['verify' => $verifySsl])
                ->withBasicAuth($key, $secret)
                ->timeout(10)
                ->post($endpoint.'/api/trafficshaper/rule/searchRule', [
                    'current' => 1,
                    'rowCount' => -1,
                    'searchPhrase' => '',
                ]);

            $response->throw();
            $data = $response->json();

            $rules = [['uuid' => '', 'description' => 'None (no rate limiting)']];

            foreach ($data['rows'] ?? [] as $rule) {
                $rules[] = [
                    'uuid' => $rule['uuid'] ?? '',
                    'description' => ($rule['description'] ?? 'Unnamed rule').' (seq: '.($rule['sequence'] ?? '?').')',
                ];
            }

            return response()->json(['rules' => $rules]);
        } catch (Throwable $throwable) {
            return response()->json(['rules' => [], 'error' => 'Failed to fetch shaper rules: '.$throwable->getMessage()]);
        }
    }

    public function opnsenseZones(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('opnsense');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));

            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $key = $config['key'] ?? '';
            $secret = $config['secret'] ?? '';
            $verifySsl = (bool) ($config['verify_ssl'] ?? true);

            if ($endpoint === '') {
                return response()->json([
                    'zones' => [],
                    'error' => 'OPNsense endpoint is not configured.',
                ]);
            }

            if ($key === '' || $secret === '') {
                return response()->json([
                    'zones' => [],
                    'error' => 'OPNsense API key and secret are required.',
                ]);
            }

            $response = Http::withOptions(['verify' => $verifySsl])
                ->withBasicAuth($key, $secret)
                ->timeout(10)
                ->get($endpoint.'/api/captiveportal/settings/get');

            $response->throw();
            $data = $response->json();

            $zones = [];
            $zonesData = $data['zone']['zones']['zone'] ?? [];

            foreach ($zonesData as $uuid => $zone) {
                $zoneId = $zone['zoneid'] ?? '';
                $description = $zone['description'] ?? 'Zone '.$zoneId;
                $zones[] = [
                    'id' => (string) $zoneId,
                    'name' => $description.' (ID: '.$zoneId.')',
                ];
            }

            usort($zones, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

            return response()->json(['zones' => $zones]);
        } catch (Throwable $throwable) {
            return response()->json(['zones' => [], 'error' => 'Failed to fetch zones: '.$throwable->getMessage()]);
        }
    }

    public function piholeGroups(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('pihole');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));

            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $password = $config['password'] ?? '';

            if ($endpoint === '') {
                return response()->json([
                    'groups' => [],
                    'error' => 'Pi-hole endpoint is not configured.',
                ]);
            }

            if ($password === '') {
                return response()->json([
                    'groups' => [],
                    'error' => 'Pi-hole password is not configured.',
                ]);
            }

            $verifySsl = (bool) ($config['verify_ssl'] ?? true);

            // Step 1: Authenticate — matches PiHoleService::getSessionId() pattern
            $authResponse = Http::withOptions(['verify' => $verifySsl])
                ->timeout(10)
                ->asJson()
                ->post($endpoint.'/api/auth', ['password' => $password]);

            if (! $authResponse->successful()) {
                return response()->json([
                    'groups' => [],
                    'error' => 'Pi-hole authentication failed (HTTP '.$authResponse->status().'). Check your password.',
                ]);
            }

            /** @var string $sid */
            $sid = $authResponse->json('session.sid', '');

            if ($sid === '') {
                return response()->json([
                    'groups' => [],
                    'error' => 'Pi-hole auth succeeded but no session ID found in response.',
                ]);
            }

            // Step 2: Fetch groups — uses X-FTL-SID header per Pi-hole v6 API
            $response = Http::withOptions(['verify' => $verifySsl])
                ->withHeaders(['X-FTL-SID' => $sid])
                ->timeout(10)
                ->get($endpoint.'/api/groups');

            if (! $response->successful()) {
                return response()->json([
                    'groups' => [],
                    'error' => 'Failed to fetch Pi-hole groups (HTTP '.$response->status().').',
                ]);
            }

            /** @var array<int, array{id: int, name?: string, enabled?: bool}> $groups */
            $groups = $response->json('groups', []);

            return response()->json(['groups' => collect($groups)->map(fn (array $g): array => [
                'id' => $g['id'],
                'name' => $g['name'] ?? "Group {$g['id']}",
                'enabled' => $g['enabled'] ?? true,
            ])->all()]);
        } catch (Throwable $e) {
            return response()->json(['groups' => [], 'error' => $e->getMessage()]);
        }
    }
}
