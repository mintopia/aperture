<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
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

        return Inertia::render('Portal/Dashboard', [
            'blocks' => $blocks,
            'currentIp' => $ip->address,
            'ipAllowed' => (bool) $ip->allowed,
        ]);
    }
}
