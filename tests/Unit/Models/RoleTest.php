<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_role(): void
    {
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Administrator';
        $role->save();

        $this->assertDatabaseHas('roles', ['code' => 'admin']);
    }

    public function test_to_string_format(): void
    {
        $role = new Role;
        $role->id = 1;
        $role->code = 'admin';
        $role->name = 'Administrator';

        $str = (string) $role;
        $this->assertStringContainsString('Role', $str);
        $this->assertStringContainsString('1', $str);
        $this->assertStringContainsString('admin', $str);
    }
}
