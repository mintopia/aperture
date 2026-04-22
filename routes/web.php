<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DhcpController;
use App\Http\Controllers\Admin\DnsDetectionSettingsController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\Ipv6DetectionSettingsController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SwitchManagementController;
use App\Http\Controllers\Admin\SwitchPortController;
use App\Http\Controllers\Admin\TestConnectionController;
use App\Http\Controllers\Admin\ThemeSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaptivePortalController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PageViewController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DnsFilterController;
use App\Http\Controllers\Portal\StatsController;
use App\Http\Controllers\PortalController;
use App\Http\Middleware\EnsureAccountSecurityVerified;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Captive portal / login
Route::get('/captive', [CaptivePortalController::class, 'index'])->name('captive.index');
Route::get('/captive/poll/{deviceCode}', [CaptivePortalController::class, 'poll'])->name('captive.poll');
Route::get('/captive/interstitial', [CaptivePortalController::class, 'interstitial'])->name('captive.interstitial');

// Public content pages
Route::get('/content/{slug}', [PageViewController::class, 'show'])->name('content.show');

Route::middleware(['guest'])->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'authenticate']);
});

// Passkey registration (requires auth)
Route::middleware(['auth', EnsureAccountSecurityVerified::class])->prefix('passkeys')->group(function () {
    Route::post('/register/options', [PasskeyController::class, 'registerOptions'])->name('passkeys.register.options');
    Route::post('/register', [PasskeyController::class, 'register'])->name('passkeys.register');
    Route::delete('/{credentialId}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy');
});

// Passkey authentication (guest)
Route::middleware(['guest'])->prefix('passkeys')->group(function () {
    Route::post('/login/options', [PasskeyController::class, 'loginOptions'])->name('passkeys.login.options');
    Route::post('/login', [PasskeyController::class, 'login'])->name('passkeys.login');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('home');
    Route::get('/status', [PortalController::class, 'status'])->name('status');
    Route::post('/ipv6', [PortalController::class, 'ipv6'])->name('ipv6')->withoutMiddleware(VerifyCsrfToken::class);

    // Account settings
    Route::prefix('account')->group(function () {
        Route::get('/settings', [AccountController::class, 'show'])->name('account.settings');
        Route::post('/settings/verify', [AccountController::class, 'verify'])->name('account.verify');
        Route::middleware(EnsureAccountSecurityVerified::class)->group(function (): void {
            Route::put('/settings/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
            Route::delete('/settings/password', [AccountController::class, 'clearPassword'])->name('account.password.clear');
        });
    });

    // New portal routes
    Route::prefix('portal')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('portal.dashboard');
        Route::post('/dns-filter/toggle', [DnsFilterController::class, 'toggle'])->name('portal.dns-filter.toggle');
        Route::get('/stats/bandwidth', [StatsController::class, 'bandwidth'])->name('portal.stats.bandwidth');
    });

    Route::middleware(['can:admin'])->name('admin.')->prefix('/admin')->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');
        Route::post('/reset', [HomeController::class, 'reset'])->name('reset');

        // Search
        Route::get('/search', [SearchController::class, 'search'])->name('search');

        // Users
        Route::resource('users', UserController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('users/{user}/block', [UserController::class, 'block'])->name('users.block');

        // IP Addresses
        Route::resource('ips', IpAddressController::class)->only('index', 'show', 'store', 'create');
        Route::post('ips/{ip}/port', [IpAddressController::class, 'port'])->name('ips.port');
        Route::post('ips/{ip}/internet', [IpAddressController::class, 'internet'])->name('ips.internet');
        Route::post('ips/{ip}/limit', [IpAddressController::class, 'limit'])->name('ips.limit');
        Route::get('ips/{ip}/bandwidth', [IpAddressController::class, 'bandwidth'])->name('ips.bandwidth');

        // DHCP
        Route::get('/dhcp', [DhcpController::class, 'index'])->name('dhcp.index');
        Route::get('/dhcp/leases', [DhcpController::class, 'leases'])->name('dhcp.leases');

        // Content blocks
        Route::put('/content/layout', [ContentController::class, 'updateLayout'])->name('content.layout.update');

        // Content settings (must be before content resource to avoid {content} wildcard conflict)
        Route::get('/content/settings', [GeneralSettingsController::class, 'show'])->name('content.settings');
        Route::put('/content/settings', [GeneralSettingsController::class, 'update'])->name('content.settings.update');

        Route::resource('content', ContentController::class)->except(['create', 'edit', 'show']);

        // Content pages
        Route::resource('content/pages', PageController::class)
            ->except(['show'])
            ->names('content.pages');

        // Switch Management (new top-level section)
        Route::get('/switches', [SwitchManagementController::class, 'index'])->name('switches.index');
        Route::get('/switches/create', [SwitchManagementController::class, 'create'])->name('switches.create');
        Route::post('/switches', [SwitchManagementController::class, 'store'])->name('switches.store');
        Route::get('/switches/{switchConfig}', [SwitchManagementController::class, 'show'])->name('switches.show');
        Route::get('/switches/{switchConfig}/edit', [SwitchManagementController::class, 'edit'])->name('switches.edit');
        Route::put('/switches/{switchConfig}', [SwitchManagementController::class, 'update'])->name('switches.update');
        Route::delete('/switches/{switchConfig}', [SwitchManagementController::class, 'destroy'])->name('switches.destroy');
        Route::post('/switches/{switchConfig}/sync', [SwitchManagementController::class, 'sync'])->name('switches.sync');
        Route::post('/switches/{switchConfig}/test', [SwitchManagementController::class, 'testConnection'])->name('switches.test-connection');
        Route::get('/switches/{switchConfig}/config', [SwitchManagementController::class, 'config'])->name('switches.config');

        // Switch Port Management
        Route::get('/switches/{switchConfig}/ports/{portId}', [SwitchPortController::class, 'show'])->name('switches.ports.show')->where('portId', '.+');
        Route::post('/switches/{switchConfig}/ports/{portId}/shutdown', [SwitchPortController::class, 'shutdown'])->name('switches.ports.shutdown')->where('portId', '.+');
        Route::post('/switches/{switchConfig}/ports/{portId}/enable', [SwitchPortController::class, 'enable'])->name('switches.ports.enable')->where('portId', '.+');
        Route::post('/switches/{switchConfig}/ports/{portId}/bounce', [SwitchPortController::class, 'bounce'])->name('switches.ports.bounce')->where('portId', '.+');

        // Settings
        Route::get('/settings/integrations', [SettingsController::class, 'integrations'])->name('settings.integrations');
        Route::get('/settings/theme', [ThemeSettingsController::class, 'show'])->name('settings.theme');
        Route::put('/settings/theme', [ThemeSettingsController::class, 'update'])->name('settings.theme.update');
        Route::get('/settings/ipv6-detection', [Ipv6DetectionSettingsController::class, 'show'])->name('settings.ipv6-detection');
        Route::put('/settings/ipv6-detection', [Ipv6DetectionSettingsController::class, 'update'])->name('settings.ipv6-detection.update');
        Route::get('/settings/dns-detection', [DnsDetectionSettingsController::class, 'show'])->name('settings.dns-detection');
        Route::put('/settings/dns-detection', [DnsDetectionSettingsController::class, 'update'])->name('settings.dns-detection.update');

        Route::post('/settings/test/switch/{switchConfig}', [TestConnectionController::class, 'testSwitch'])->name('settings.test.switch');
        Route::post('/settings/test/{service}', [TestConnectionController::class, 'test'])->name('settings.test');

        // Per-service integration routes
        Route::post('/settings/integrations/pihole/groups', [IntegrationController::class, 'piholeGroups'])->name('settings.integrations.pihole.groups');
        Route::post('/settings/integrations/opnsense/shaper-rules', [IntegrationController::class, 'opnsenseShaperRules'])->name('settings.integrations.opnsense.shaper-rules');
        Route::post('/settings/integrations/opnsense/zones', [IntegrationController::class, 'opnsenseZones'])->name('settings.integrations.opnsense.zones');
        Route::get('/settings/integrations/{service}', [IntegrationController::class, 'show'])->name('settings.integrations.show');
        Route::put('/settings/integrations/{service}', [IntegrationController::class, 'update'])->name('settings.integrations.service.update');
        Route::put('/settings/capabilities', [IntegrationController::class, 'toggleCapability'])->name('settings.capabilities.update');
        Route::get('/settings/integrations/{service}/health-log', [IntegrationController::class, 'healthLog'])->name('settings.integrations.health-log');
    });
});
