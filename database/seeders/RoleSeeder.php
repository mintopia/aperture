<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'user' => 'User',
            'admin' => 'Admin',
        ];
        foreach ($roles as $code => $name) {
            $role = Role::whereCode($code)->first();
            $verb = 'Updated';
            if (!$role) {
                $verb = 'Created';
                $role = new Role;
                $role->code = $code;
            }
            $role->name = $name;
            $role->save();
            Log::info("{$role} {$verb}");
        }
    }
}
