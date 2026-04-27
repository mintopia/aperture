<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSwitchRequest;
use App\Http\Requests\Admin\UpdateSwitchRequest;
use App\Http\Resources\SwitchConfigResource;
use App\Http\Resources\SwitchPortResource;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SwitchIndexDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SwitchManagementController extends Controller
{
    public function __construct(
        protected SwitchServiceFactory $factory,
        protected SwitchIndexDataService $indexDataService,
    ) {}

    public function index(Request $request): Response
    {
        $data = $this->indexDataService->assemble($request);

        return Inertia::render('Admin/Switches/Index', [
            ...$data,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches'],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Switches/Create', [
            'switchTypes' => $this->availableSwitchTypes(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches', 'href' => route('admin.switches.index')],
                ['label' => 'Add Switch'],
            ],
        ]);
    }

    public function store(StoreSwitchRequest $request): RedirectResponse
    {
        $switchConfig = SwitchConfig::create($request->validated());

        return redirect()->route('admin.switches.show', $switchConfig)
            ->with('success', 'Switch created successfully.');
    }

    public function show(SwitchConfig $switchConfig): Response
    {
        $ports = $switchConfig->switchPorts()
            ->orderBy('port_name')
            ->get();
        $latestSync = $switchConfig->latestSyncRun;

        return Inertia::render('Admin/Switches/Show', [
            'switchConfig' => (new SwitchConfigResource($switchConfig))->toArray(request()),
            'ports' => SwitchPortResource::collection($ports)->resolve(),
            'latestSync' => $latestSync,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches', 'href' => route('admin.switches.index')],
                ['label' => $switchConfig->name],
            ],
        ]);
    }

    public function edit(SwitchConfig $switchConfig): Response
    {
        return Inertia::render('Admin/Switches/Edit', [
            'switchConfig' => (new SwitchConfigResource($switchConfig))->toArray(request()),
            'switchTypes' => $this->availableSwitchTypes(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches', 'href' => route('admin.switches.index')],
                ['label' => $switchConfig->name, 'href' => route('admin.switches.show', $switchConfig)],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function update(UpdateSwitchRequest $request, SwitchConfig $switchConfig): RedirectResponse
    {
        $validated = $request->validated();

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        if (empty($validated['enable_password'])) {
            unset($validated['enable_password']);
        }

        $switchConfig->update($validated);

        return back()->with('success', 'Switch updated successfully.');
    }

    public function destroy(SwitchConfig $switchConfig): RedirectResponse
    {
        $switchConfig->delete();

        return redirect()->route('admin.switches.index')
            ->with('success', 'Switch deleted successfully.');
    }

    public function sync(SwitchConfig $switchConfig): RedirectResponse
    {
        SyncSwitchPortsJob::dispatch($switchConfig);

        return back()->with('success', 'Switch sync has been queued.');
    }

    public function testConnection(SwitchConfig $switchConfig): JsonResponse
    {
        try {
            $adapter = $this->factory->make($switchConfig);
            $adapter->getAllPorts();

            return response()->json([
                'success' => true,
                'message' => 'Connection successful.',
            ]);
        } catch (Throwable $throwable) {
            Log::warning('Switch connection test failed', ['switch' => $switchConfig->id, 'error' => $throwable->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed. Check the switch configuration and try again.',
            ]);
        }
    }

    public function config(SwitchConfig $switchConfig): Response
    {
        $config = '';

        try {
            $adapter = $this->factory->make($switchConfig);
            $config = $adapter->getRunningConfig();
        } catch (Throwable $throwable) {
            Log::warning('Failed to retrieve switch config', [
                'switch' => $switchConfig->id,
                'error' => $throwable->getMessage(),
            ]);
        }

        return Inertia::render('Admin/Switches/Config', [
            'switchConfig' => (new SwitchConfigResource($switchConfig))->toArray(request()),
            'config' => $config,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches', 'href' => route('admin.switches.index')],
                ['label' => $switchConfig->name, 'href' => route('admin.switches.show', $switchConfig)],
                ['label' => 'Config'],
            ],
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function availableSwitchTypes(): array
    {
        return [
            ['value' => 'cisco', 'label' => 'Cisco IOS'],
        ];
    }
}
