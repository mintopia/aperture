<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return Inertia::render('Admin/Settings/Integrations', [
            'integrations' => [
                'opnsense' => IntegrationConfig::getAll('opnsense'),
                'librenms' => IntegrationConfig::getAll('librenms'),
                'ntopng' => IntegrationConfig::getAll('ntopng'),
                'pihole' => IntegrationConfig::getAll('pihole'),
                'dhcp' => IntegrationConfig::getAll('dhcp'),
                'dns' => IntegrationConfig::getAll('dns'),
                'auto_allow' => IntegrationConfig::getAll('auto_allow'),
                'ipv6' => IntegrationConfig::getAll('ipv6'),
            ],
        ]);
    }

    public function updateIntegrations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opnsense.endpoint' => 'nullable|url|max:500',
            'opnsense.key' => 'nullable|string|max:500',
            'opnsense.secret' => 'nullable|string|max:500',
            'opnsense.captive_portal_id' => 'nullable|string|max:100',
            'opnsense.verify_ssl' => 'nullable|string|in:0,1',
            'opnsense.zone_id' => 'nullable|string|max:100',
            'opnsense.ratelimit_up_uuid' => 'nullable|string|max:500',
            'opnsense.ratelimit_down_uuid' => 'nullable|string|max:500',
            'librenms.endpoint' => 'nullable|url|max:500',
            'librenms.api_key' => 'nullable|string|max:500',
            'librenms.enabled' => 'nullable|string|in:0,1',
            'ntopng.endpoint' => 'nullable|url|max:500',
            'ntopng.username' => 'nullable|string|max:255',
            'ntopng.password' => 'nullable|string|max:500',
            'ntopng.interface' => 'nullable|string|max:100',
            'ntopng.enabled' => 'nullable|string|in:0,1',
            'pihole.endpoint' => 'nullable|url|max:500',
            'pihole.password' => 'nullable|string|max:500',
            'pihole.noblock_group_id' => 'nullable|integer|min:1',
            'pihole.enabled' => 'nullable|string|in:0,1',
            'pihole.verify_ssl' => 'nullable|string|in:0,1',
            'dhcp.enabled' => 'nullable|string|in:0,1',
            'dhcp.endpoint' => 'nullable|url|max:500',
            'dhcp.key' => 'nullable|string|max:500',
            'dhcp.secret' => 'nullable|string|max:500',
            'dhcp.verify_ssl' => 'nullable|string|in:0,1',
            'dhcp.pool_size' => 'nullable|integer|min:0',
            'dns.expected_server' => 'nullable|string|max:255',
            'dns.probe_domain' => 'nullable|string|max:255',
            'auto_allow.enabled' => 'nullable|string|in:0,1',
            'auto_allow.oui_prefixes' => 'nullable|string|max:2000',
            'auto_allow.scan_interval' => 'nullable|integer|min:1',
            'ipv6.detection_enabled' => 'nullable|string|in:0,1',
            'ipv6.detection_endpoint' => 'nullable|url|max:500',
        ]);

        foreach ($validated as $integration => $fields) {
            // @codeCoverageIgnoreStart
            if (! is_array($fields)) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            foreach ($fields as $key => $value) {
                $encrypted = in_array($key, IntegrationConfig::ENCRYPTED_KEYS, true);
                IntegrationConfig::setValue($integration, $key, $value, $encrypted);
            }
        }

        return back()->with('success', 'Integration settings updated.');
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
            'theme_name' => 'required|string|in:cool-neon,warm-neon,matrix,amber-glow',
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
}
