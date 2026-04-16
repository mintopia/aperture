<?php

use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DhcpController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\PortController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\TestConnectionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaptivePortalController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\PiHoleController;
use App\Http\Controllers\Portal\StatsController;
use App\Http\Controllers\PortalController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/logout', [AuthController::class, 'logout'])->name('logout');

// Captive portal / login
Route::get('/captive', [CaptivePortalController::class, 'index'])->name('captive.index');
Route::get('/captive/poll/{deviceCode}', [CaptivePortalController::class, 'poll'])->name('captive.poll');
Route::get('/captive/interstitial', [CaptivePortalController::class, 'interstitial'])->name('captive.interstitial');

Route::middleware(['guest'])->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'authenticate']);
});

// Passkey registration (requires auth)
Route::middleware(['auth'])->prefix('passkeys')->group(function () {
    Route::post('/register/options', [PasskeyController::class, 'registerOptions'])->name('passkeys.register.options');
    Route::post('/register', [PasskeyController::class, 'register'])->name('passkeys.register');
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

    // New portal routes
    Route::prefix('portal')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('portal.dashboard');
        Route::post('/pihole/toggle', [PiHoleController::class, 'toggle'])->name('portal.pihole.toggle');
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

        // Ports
        Route::get('/ports', [PortController::class, 'index'])->name('ports.index');
        Route::get('/ports/{portId}', [PortController::class, 'show'])->name('ports.show');
        Route::post('/ports/{portId}/shutdown', [PortController::class, 'shutdown'])->name('ports.shutdown');
        Route::post('/ports/{portId}/enable', [PortController::class, 'enable'])->name('ports.enable');

        // DHCP
        Route::get('/dhcp', [DhcpController::class, 'index'])->name('dhcp.index');
        Route::get('/dhcp/leases', [DhcpController::class, 'leases'])->name('dhcp.leases');

        // Stats
        Route::get('/stats', [AdminStatsController::class, 'index'])->name('stats.index');
        Route::get('/stats/bandwidth', [AdminStatsController::class, 'bandwidth'])->name('stats.bandwidth');
        Route::get('/stats/top-talkers', [AdminStatsController::class, 'topTalkers'])->name('stats.top-talkers');

        // Content blocks
        Route::resource('content', ContentController::class)->except(['create', 'edit']);
        Route::post('/content/reorder', [ContentController::class, 'reorder'])->name('content.reorder');

        // Settings
        Route::get('/settings/integrations', [SettingsController::class, 'integrations'])->name('settings.integrations');
        Route::get('/settings/theme', [SettingsController::class, 'theme'])->name('settings.theme');
        Route::put('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme.update');
        Route::get('/settings/event', [SettingsController::class, 'event'])->name('settings.event');
        Route::put('/settings/event', [SettingsController::class, 'updateEvent'])->name('settings.event.update');
        Route::get('/settings/portal', [SettingsController::class, 'portal'])->name('settings.portal');
        Route::put('/settings/portal', [SettingsController::class, 'updatePortal'])->name('settings.portal.update');

        Route::get('/settings/switches', [SettingsController::class, 'switches'])->name('settings.switches');
        Route::post('/settings/switches', [SettingsController::class, 'storeSwitch'])->name('settings.switches.store');
        Route::put('/settings/switches/{switchConfig}', [SettingsController::class, 'updateSwitch'])->name('settings.switches.update');
        Route::delete('/settings/switches/{switchConfig}', [SettingsController::class, 'destroySwitch'])->name('settings.switches.destroy');

        Route::post('/settings/test/opnsense', [TestConnectionController::class, 'testOpnsense'])->name('settings.test.opnsense');
        Route::post('/settings/test/librenms', [TestConnectionController::class, 'testLibrenms'])->name('settings.test.librenms');
        Route::post('/settings/test/ntopng', [TestConnectionController::class, 'testNtopng'])->name('settings.test.ntopng');
        Route::post('/settings/test/pihole', [TestConnectionController::class, 'testPihole'])->name('settings.test.pihole');
        Route::post('/settings/test/borealis', [TestConnectionController::class, 'testBorealis'])->name('settings.test.borealis');
        Route::post('/settings/test/switch/{switchConfig}', [TestConnectionController::class, 'testSwitch'])->name('settings.test.switch');

        // Per-service integration routes
        Route::get('/settings/integrations/{service}', [IntegrationController::class, 'show'])->name('settings.integrations.show');
        Route::put('/settings/integrations/{service}', [IntegrationController::class, 'update'])->name('settings.integrations.service.update');
        Route::put('/settings/capabilities', [IntegrationController::class, 'toggleCapability'])->name('settings.capabilities.update');
        Route::get('/settings/integrations/{service}/health-log', [IntegrationController::class, 'healthLog'])->name('settings.integrations.health-log');
    });
});
