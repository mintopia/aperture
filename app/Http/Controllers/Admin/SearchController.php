<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $request->input('q'));
        $pattern = sprintf('%%%s%%', $escaped);

        $users = User::where('nickname', 'like', $pattern)
            ->orWhere('email', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'nickname', 'email']);

        $ips = IpAddress::where('address', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'address', 'internet_enabled']);

        $macs = MacAddress::where('mac_address', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'mac_address', 'description']);

        $switches = SwitchConfig::where('name', 'like', $pattern)
            ->orWhere('hostname', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'name', 'hostname']);

        $dhcpHostnames = DhcpLease::whereNotNull('hostname')
            ->where('hostname', 'like', $pattern)
            ->select('hostname', 'mac_address_id')
            ->selectRaw('MIN(id) as id')
            ->groupBy('hostname', 'mac_address_id')
            ->limit(5)
            ->get();

        $auditLogs = AuditLog::where('action', 'like', $pattern)
            ->orWhere('process', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'action', 'process', 'created_at']);

        return response()->json([
            'users' => $users,
            'ips' => $ips,
            'macs' => $macs,
            'switches' => $switches,
            'dhcp_hostnames' => $dhcpHostnames,
            'audit_logs' => $auditLogs,
        ]);
    }
}
