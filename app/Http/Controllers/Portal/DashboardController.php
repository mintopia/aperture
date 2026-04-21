<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp((string) $request->getClientIp());

        if (! $user->blocked) {
            $ip->allow(true);
        }

        $blocks = ContentBlock::active()->get();

        $checkUrl = Setting::get('dns.check_url');
        $warningMessage = Setting::get('dns.warning_message');

        return Inertia::render('Portal/Dashboard', [
            'blocks' => $blocks,
            'blockContext' => [
                'currentIp' => $ip->address,
                'ipAllowed' => (bool) $ip->allowed,
                'macAddress' => $ip->mac,
                'user' => $user->parameters()->pluck('value', 'key'),
            ],
            'dnsDetection' => $checkUrl ? [
                'checkUrl' => $checkUrl,
                'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
            ] : null,
        ]);
    }
}
