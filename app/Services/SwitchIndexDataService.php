<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\SwitchConfigResource;
use App\Models\SwitchConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SwitchIndexDataService
{
    private const SORTABLE_COLUMNS = ['name', 'hostname', 'type', 'enabled'];

    /**
     * Assemble all display data for the switch index page.
     *
     * @return array{
     *     switches: Collection<int, mixed>,
     *     filters: object,
     * }
     */
    public function assemble(Request $request): array
    {
        $order = 'name';
        $direction = 'asc';

        if (in_array($request->input('order'), self::SORTABLE_COLUMNS)) {
            $order = $request->input('order');
        }

        if (in_array($request->input('direction'), ['asc', 'desc'])) {
            $direction = $request->input('direction');
        }

        $query = SwitchConfig::query()
            ->withCount([
                'switchPorts',
                'switchPorts as ports_up_count' => fn ($q) => $q->whereIn('status', ['connected', 'up']),
                'switchPorts as ports_down_count' => fn ($q) => $q->whereIn('status', ['down', 'notconnect']),
                'switchPorts as ports_error_count' => fn ($q) => $q->where('status', 'err-disabled'),
            ])
            ->with('latestSyncRun');

        $search = (string) $request->input('search', '');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', sprintf('%%%s%%', $search))
                    ->orWhere('hostname', 'like', sprintf('%%%s%%', $search));
            });
        }

        if (in_array($request->input('status'), ['enabled', 'disabled'])) {
            $query->where('enabled', $request->input('status') === 'enabled');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $switches = $query->orderBy($order, $direction)
            ->get()
            ->map(fn (SwitchConfig $s): array => [
                ...(new SwitchConfigResource($s))->toArray(request()),
                'port_count' => $s->switch_ports_count,
                'ports_up' => $s->ports_up_count,
                'ports_down' => $s->ports_down_count,
                'ports_error' => $s->ports_error_count,
                'last_synced_at' => $s->latestSyncRun?->finished_at,
                'latest_sync_status' => $s->latestSyncRun?->status,
            ]);

        $filters = (object) [
            'search' => $search,
            'status' => (string) $request->input('status', ''),
            'type' => (string) $request->input('type', ''),
            'order' => $order,
            'direction' => $direction,
        ];

        return [
            'switches' => $switches,
            'filters' => $filters,
        ];
    }
}
