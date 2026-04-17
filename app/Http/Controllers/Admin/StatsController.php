<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Interfaces\TrafficMonitorInterface;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function index(TrafficMonitorInterface $trafficMonitor): Response
    {
        return Inertia::render('Admin/Stats/Index', [
            'aggregateStats' => $trafficMonitor->getAggregateStats(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Stats'],
            ],
        ]);
    }

    public function bandwidth(TrafficMonitorInterface $trafficMonitor): Response
    {
        return Inertia::render('Admin/Stats/Bandwidth', [
            'topTalkers' => $trafficMonitor->getTopTalkers(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Stats', 'href' => route('admin.stats.index')],
                ['label' => 'Bandwidth'],
            ],
        ]);
    }

    public function topTalkers(TrafficMonitorInterface $trafficMonitor): JsonResponse
    {
        return response()->json([
            'topTalkers' => $trafficMonitor->getTopTalkers(),
        ]);
    }
}
