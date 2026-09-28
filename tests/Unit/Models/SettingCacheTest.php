<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SettingCacheTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function settingQueries(): int
    {
        return collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'settings'))->count();
    }

    public function test_cached_reads_do_not_requery(): void
    {
        Setting::set('cache.one', 'One', 'a');
        Setting::flushCache();

        DB::enableQueryLog();
        $this->assertSame('a', Setting::get('cache.one'));
        $this->assertSame('a', Setting::get('cache.one'));
        $this->assertSame('d', Setting::get('cache.missing', 'd'));

        $this->assertSame(1, $this->settingQueries());
    }

    public function test_set_invalidates_cache(): void
    {
        Setting::set('cache.two', 'Two', 'a');
        $this->assertSame('a', Setting::get('cache.two'));

        Setting::set('cache.two', 'Two', 'b');

        $this->assertSame('b', Setting::get('cache.two'));
    }

    public function test_delete_invalidates_cache(): void
    {
        Setting::set('cache.three', 'Three', 'a');
        $this->assertSame('a', Setting::get('cache.three'));

        Setting::whereCode('cache.three')->firstOrFail()->delete();

        $this->assertSame('x', Setting::get('cache.three', 'x'));
    }

    public function test_bulk_writes_invalidate_cache(): void
    {
        Setting::set('cache.four', 'Four', 'a');
        $this->assertSame('a', Setting::get('cache.four'));

        Setting::query()->where('code', 'cache.four')->update(['value' => json_encode(['value' => 'b'])]);
        $this->assertSame('b', Setting::get('cache.four'));

        Setting::query()->where('code', 'cache.four')->delete();
        $this->assertNull(Setting::get('cache.four'));
    }

    public function test_site_title_change_is_reflected_in_next_render_without_reboot(): void
    {
        View::addNamespace('t', sys_get_temp_dir());
        file_put_contents(sys_get_temp_dir().'/title.blade.php', '{{ $siteTitle }}');

        Setting::set('general.site_title', 'Site Title', 'First');
        $this->assertSame('First', view('t::title')->render());

        Setting::set('general.site_title', 'Site Title', 'Second');
        $this->assertSame('Second', view('t::title')->render());
    }
}
