<?php

declare(strict_types=1);

namespace App\Http\Controllers\E2e;

use App\Http\Controllers\Controller;
use App\Services\Auth\E2e\FakeDeviceFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeviceApprovalController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $deviceCode = Cache::get(FakeDeviceFlowService::USER_CODE_KEY.$request->string('user_code'));
        if (! $deviceCode) {
            return response()->json(['status' => 'unknown_code'], 404);
        }

        Cache::put(FakeDeviceFlowService::APPROVED_KEY.$deviceCode, true, now()->addMinutes(10));

        return response()->json(['status' => 'approved']);
    }
}
