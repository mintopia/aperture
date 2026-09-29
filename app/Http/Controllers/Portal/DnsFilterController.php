<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DnsFilterController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enabled = ! $user->dns_filtering_enabled;
        $user->dns_filtering_enabled = $enabled;
        $user->save();

        AuditLog::record(
            action: 'user.dns_filter_toggled',
            subject: $user,
            process: 'portal',
            metadata: ['ip' => $request->getClientIp(), 'enabled' => $enabled],
        );

        return response()->json(['enabled' => $enabled]);
    }
}
