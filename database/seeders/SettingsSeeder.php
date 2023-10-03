<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            (object)[
                'code' => 'auth.linkemails',
                'name' => 'Link user auth by email',
                'description' => 'If a user authenticates using multiple methods, link them using their email address.',
                'value' => true,
            ]
        ];

        foreach ($settings as $config) {
            $setting = Setting::whereCode($config->code)->first();
            if (!$setting) {
                $setting = new Setting;
                $setting->code = $config->code;
                // Set value here only on creation to not override values
                $setting->value = $config->value;
            }
            $setting->name = $config->name;
            $setting->description = $config->description;
            $setting->save();
        }
    }
}
