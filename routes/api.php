<?php

use App\Http\Controllers\Api\CaptivePortalApiController;
use Illuminate\Support\Facades\Route;

Route::get('/captive-portal', CaptivePortalApiController::class)->name('captive-portal');
