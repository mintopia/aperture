<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class PageViewController extends Controller
{
    public function show(string $slug): Response
    {
        $page = Page::where('slug', $slug)->firstOrFail();

        return Inertia::render('Content/Show', ['page' => $page]);
    }
}
