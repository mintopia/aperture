<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => (int) $request->input('perPage', 20),
            'action' => (string) $request->input('action', ''),
            'process' => (string) $request->input('process', ''),
            'subject_type' => (string) $request->input('subject_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $query = AuditLog::query()
            ->with(['subject', 'related', 'actor'])
            ->orderByDesc('created_at');

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
            'subject_url' => $this->resolveEntityUrl($log->subject_type, $log->subject),
            'related_type' => $log->related_type ? class_basename($log->related_type) : null,
            'related_id' => $log->related_id,
            'related_url' => $this->resolveEntityUrl($log->related_type, $log->related),
            'actor_type' => $log->actor_type ? class_basename($log->actor_type) : null,
            'actor_id' => $log->actor_id,
            'actor_url' => $this->resolveEntityUrl($log->actor_type, $log->actor),
            'process' => $log->process,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at->toIso8601String(),
        ]);

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

    private function resolveEntityUrl(?string $type, ?Model $model): ?string
    {
        if ($type === null || $model === null) {
            return null;
        }

        return match (class_basename($type)) {
            'User' => route('admin.users.show', $model),
            'IpAddress' => route('admin.ips.show', $model),
            'MacAddress' => route('admin.macs.show', $model),
            'SwitchConfig' => route('admin.switches.show', $model),
            default => null,
        };
    }
}
