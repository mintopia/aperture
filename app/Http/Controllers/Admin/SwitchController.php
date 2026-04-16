<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSwitchRequest;
use App\Http\Requests\Admin\UpdateSwitchRequest;
use App\Models\SwitchConfig;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SwitchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Switches', [
            'switches' => SwitchConfig::all()->map(fn (SwitchConfig $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'hostname' => $s->hostname,
                'type' => $s->type,
                'username' => $s->username,
                'enabled' => $s->enabled,
                'port' => $s->port,
                'timeout' => $s->timeout,
            ]),
        ]);
    }

    public function store(StoreSwitchRequest $request): RedirectResponse
    {
        SwitchConfig::create($request->validated());

        return back()->with('success', 'Switch added.');
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

        return back()->with('success', 'Switch updated.');
    }

    public function destroy(SwitchConfig $switchConfig): RedirectResponse
    {
        $switchConfig->delete();

        return back()->with('success', 'Switch removed.');
    }
}
