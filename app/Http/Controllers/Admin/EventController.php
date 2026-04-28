<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $search = (string) $request->input('search', '');
        $perPage = (int) $request->input('perPage', 50);

        $query = SystemEvent::query()->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('type', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $events = $query->paginate($perPage)->appends(['search' => $search, 'perPage' => $perPage]);

        $events->getCollection()->transform(fn (SystemEvent $event): array => [
            'id' => $event->id,
            'type' => $event->type,
            'level' => $event->level,
            'message' => $event->message,
            'created_at' => $event->created_at->toIso8601String(),
        ]);

        $totalCount = SystemEvent::count();

        return Inertia::render('Admin/Events/Index', [
            'events' => $events,
            'totalCount' => $totalCount,
            'filters' => (object) [
                'search' => $search,
                'perPage' => $perPage,
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Event Feed'],
            ],
        ]);
    }
}
