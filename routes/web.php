<?php

use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['guest'])->group(function() {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::get('/login/{provider:code}', [AuthController::class, 'redirect'])->name('login.redirect');
    Route::get('/login/{provider:code}/return', [AuthController::class, 'handle'])->name('login.handle');
});

Route::middleware(['auth'])->group(function() {
    Route::get('/', [PortalController::class, 'index'])->name('home');
    Route::get('/status', [PortalController::class, 'status'])->name('status');
    Route::middleware(['can:admin'])->name('admin.')->prefix('/admin')->group(function() {
        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::resource('users', UserController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('users/{user}/block', [UserController::class, 'block'])->name('users.block');

        Route::resource('ips', IpAddressController::class)->only('index', 'show', 'store', 'create');
        Route::post('ips/{ip}/port', [IpAddressController::class, 'port'])->name('ips.port');
        Route::post('ips/{ip}/internet', [IpAddressController::class, 'internet'])->name('ips.internet');
        Route::post('ips/{ip}/limit', [IpAddressController::class, 'limit'])->name('ips.limit');
    });
});
