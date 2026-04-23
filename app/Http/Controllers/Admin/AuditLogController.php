<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'action' => $request->input('action', ''),
            'process' => $request->input('process', ''),
            'subject_type' => $request->input('subject_type', ''),
            'date_from' => $request->input('date_from', ''),
            'date_to' => $request->input('date_to', ''),
        ];

        $query = AuditLog::query()->orderByDesc('created_at');

        if ($filters->action !== '') {
            $query->where('action', $filters->action);
        }

        if ($filters->process !== '') {
            $query->where('process', $filters->process);
        }

        if ($filters->subject_type !== '') {
            $query->where('subject_type', $filters->subject_type);
        }

        if ($filters->date_from !== '') {
            $query->where('created_at', '>=', $filters->date_from);
        }

        if ($filters->date_to !== '') {
            $query->where('created_at', '<=', $filters->date_to.' 23:59:59');
        }

        $logs = $query->paginate($filters->perPage)->appends((array) $filters);

        $logs->getCollection()->transform(fn (AuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => class_basename($log->subject_type),
            'subject_id' => $log->subject_id,
            'related_type' => $log->related_type ? class_basename($log->related_type) : null,
            'related_id' => $log->related_id,
            'actor_type' => $log->actor_type ? class_basename($log->actor_type) : null,
            'actor_id' => $log->actor_id,
            'process' => $log->process,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at->toIso8601String(),
        ]);

        // Get distinct values for filter dropdowns
        $actions = AuditLog::distinct()->pluck('action')->sort()->values();
        $processes = AuditLog::distinct()->pluck('process')->sort()->values();
        $subjectTypes = AuditLog::distinct()->pluck('subject_type')
            ->map(fn ($t): array => ['value' => $t, 'label' => class_basename($t)])
            ->sortBy('label')
            ->values();

        return Inertia::render('Admin/AuditLog/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actionOptions' => $actions,
            'processOptions' => $processes,
            'subjectTypeOptions' => $subjectTypes,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Audit Log'],
            ],
        ]);
    }
}
