<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\Setting;
use App\Models\SwitchConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $borealisEnabled = (bool) config('aperture.borealis.enabled', false);
        array_unshift($services, [
            'id' => 'borealis',
            'name' => 'Borealis',
            'enabled' => $borealisEnabled,
            'health' => null,
            'readonly' => true,
            'capabilities' => collect(['authentication', 'sso', 'user-info'])->map(fn (string $cap): array => [
                'name' => $cap,
                'active' => $borealisEnabled,
            ])->values()->all(),
        ]);

        return Inertia::render('Admin/Settings/Integrations', [
            'services' => $services,
        ]);
    }

    public function switches(): Response
    {
        return Inertia::render('Admin/Settings/Switches', [
            'switches' => SwitchConfig::all()->map(fn (SwitchConfig $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'hostname' => $s->hostname,
                'type' => $s->type,
                'username' => $s->username,
                'enabled' => $s->enabled,
                'port' => $s->port,
                'timeout' => $s->timeout,
            ]),
        ]);
    }

    public function storeSwitch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|max:255|unique:switch_configs',
            'type' => 'required|string|in:cisco',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:500',
            'enable_password' => 'nullable|string|max:500',
            'port' => 'integer|min:1|max:65535',
            'timeout' => 'integer|min:1|max:300',
        ]);

        SwitchConfig::create($validated);

        return back()->with('success', 'Switch added.');
    }

    public function updateSwitch(Request $request, SwitchConfig $switchConfig): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|max:255|unique:switch_configs,hostname,'.$switchConfig->id,
            'type' => 'required|string|in:cisco',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|max:500',
            'enable_password' => 'nullable|string|max:500',
            'port' => 'integer|min:1|max:65535',
            'timeout' => 'integer|min:1|max:300',
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        if (empty($validated['enable_password'])) {
            unset($validated['enable_password']);
        }

        $switchConfig->update($validated);

        return back()->with('success', 'Switch updated.');
    }

    public function destroySwitch(SwitchConfig $switchConfig): RedirectResponse
    {
        $switchConfig->delete();

        return back()->with('success', 'Switch removed.');
    }

    public function theme(): Response
    {
        return Inertia::render('Admin/Settings/Theme', [
            'settings' => [
                'theme_name' => Setting::get('theme.name', 'cool-neon'),
                'theme_mode' => Setting::get('theme.mode', 'dark'),
            ],
        ]);
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme_name' => 'required|string|in:default,cool-neon,warm-neon,matrix,amber-glow',
            'theme_mode' => 'required|string|in:light,dark',
        ]);

        $this->saveSetting('theme.name', 'Theme Name', $validated['theme_name']);
        $this->saveSetting('theme.mode', 'Theme Mode', $validated['theme_mode']);

        return back()->with('success', 'Theme settings updated.');
    }

    public function event(): Response
    {
        return Inertia::render('Admin/Settings/Event', [
            'settings' => [
                'event_name' => Setting::get('event.name', ''),
                'event_description' => Setting::get('event.description', ''),
            ],
        ]);
    }

    public function updateEvent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
        ]);

        $this->saveSetting('event.name', 'Event Name', $validated['event_name']);
        $this->saveSetting('event.description', 'Event Description', $validated['event_description']);

        return back()->with('success', 'Event settings updated.');
    }

    public function portal(): Response
    {
        return Inertia::render('Admin/Settings/Portal', [
            'settings' => [
                'portal_session_timeout' => Setting::get('portal.session_timeout', 86400),
                'portal_redirect_url' => Setting::get('portal.redirect_url', ''),
            ],
        ]);
    }

    public function updatePortal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'portal_session_timeout' => 'required|integer|min:300',
            'portal_redirect_url' => 'nullable|url|max:500',
        ]);

        $this->saveSetting('portal.session_timeout', 'Session Timeout', $validated['portal_session_timeout']);
        $this->saveSetting('portal.redirect_url', 'Redirect URL', $validated['portal_redirect_url']);

        return back()->with('success', 'Portal settings updated.');
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
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
