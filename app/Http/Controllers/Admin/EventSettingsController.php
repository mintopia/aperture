<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Event', [
            'settings' => [
                'event_name' => Setting::get('event.name', ''),
                'event_description' => Setting::get('event.description', ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
        ]);

        $this->saveSetting('event.name', 'Event Name', $validated['event_name']);
        $this->saveSetting('event.description', 'Event Description', $validated['event_description']);

        return back()->with('success', 'Event settings updated.');
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
    }
}
