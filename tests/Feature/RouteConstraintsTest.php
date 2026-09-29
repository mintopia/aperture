<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteConstraintsTest extends TestCase
{
    public function test_content_settings_is_not_captured_by_content_resource(): void
    {
        $route = Route::getRoutes()->match(request()->create('/admin/content/settings', 'PUT'));

        $this->assertSame(GeneralSettingsController::class.'@update', $route->getActionName());
    }

    public function test_content_resource_requires_numeric_id(): void
    {
        $route = Route::getRoutes()->match(request()->create('/admin/content/12', 'PUT'));
        $this->assertSame(ContentController::class.'@update', $route->getActionName());

        $this->assertSame('[0-9]+', Route::getRoutes()->getByName('admin.content.update')->wheres['content']);
    }

    public function test_port_id_pattern_applies_to_all_port_routes(): void
    {
        foreach (['show', 'refresh', 'shutdown', 'enable'] as $name) {
            $this->assertSame(
                '[A-Za-z][A-Za-z0-9\-]*\d+(?:/\d+){0,3}',
                Route::getRoutes()->getByName("admin.switches.ports.$name")->wheres['portId'] ?? Route::getPatterns()['portId'],
            );
        }
    }
}
