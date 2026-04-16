<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntegrationController extends Controller
{
    private function integrations(): array
    {
        return config('integrations', []);
    }

    public function show(string $service): Response
    {
        $integrations = $this->integrations();
        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $meta = $integrations[$service];
        $config = IntegrationConfig::getAll($service);
        $activeCapabilities = CapabilityAssignment::getForIntegration($service);
        $logs = ConnectionTestLog::recentFor($service, 20);
        $latestTest = $logs->first();

        return Inertia::render('Admin/Settings/IntegrationShow', [
            'service' => [
                'id' => $service,
                'name' => $meta['name'],
                'description' => $meta['description'] ?? '',
                'config' => $config,
                'capabilities' => collect($meta['capabilities'])->map(fn (string $cap): array => [
                    'name' => $cap,
                    'active' => $activeCapabilities->contains($cap),
                ])->values()->all(),
                'health' => $latestTest?->success,
                'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                    'id' => $log->id,
                    'success' => $log->success,
                    'message' => $log->message,
                    'tested_at' => $log->created_at->toIso8601String(),
                ])->values()->all(),
            ],
        ]);
    }

    public function update(Request $request, string $service): RedirectResponse
    {
        $integrations = $this->integrations();
        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $validationRules = $integrations[$service]['validation'] ?? [];
        $rules = ['config' => 'required|array'];
        foreach ($validationRules as $field => $rule) {
            $rules["config.{$field}"] = $rule;
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
                'message' => "Integration {$validated['integration']} does not support capability {$validated['capability']}.",
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
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $logs = ConnectionTestLog::recentFor($service, 20);

        return response()->json([
            'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                'id' => $log->id,
                'success' => $log->success,
                'message' => $log->message,
                'tested_at' => $log->created_at->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
