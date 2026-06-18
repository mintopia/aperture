<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function index(): Response
    {
        $pages = Page::orderBy('updated_at', 'desc')->get();

        return Inertia::render('Admin/Content/Pages/Index', [
            'pages' => $pages,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Pages'],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Content/Pages/Create', [
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Pages', 'href' => route('admin.content.pages.index')],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function store(StorePageRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Page::create($validated);

        return redirect()->route('admin.content.pages.index')->with('success', 'Page created successfully.');
    }

    public function edit(Page $page): Response
    {
        return Inertia::render('Admin/Content/Pages/Edit', [
            'page' => $page,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Pages', 'href' => route('admin.content.pages.index')],
                ['label' => $page->title],
            ],
        ]);
    }

    public function update(UpdatePageRequest $request, Page $page): RedirectResponse
    {
        $validated = $request->validated();

        $page->update($validated);

        return redirect()->route('admin.content.pages.index')->with('success', 'Page updated successfully.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect()->route('admin.content.pages.index')->with('success', 'Page deleted successfully.');
    }
}
