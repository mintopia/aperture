<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            (object) [
                'code' => 'auth.linkemails',
                'name' => 'Link user auth by email',
                'description' => 'If a user authenticates using multiple methods, link them using their email address.',
                'value' => true,
            ],
            (object) [
                'code' => 'theme.name',
                'name' => 'Theme Name',
                'description' => 'The active colour theme for the application.',
                'value' => 'cool-neon',
            ],
            (object) [
                'code' => 'theme.mode',
                'name' => 'Theme Mode',
                'description' => 'The default colour mode (light or dark).',
                'value' => 'dark',
            ],
        ];

        foreach ($settings as $config) {
            $setting = Setting::whereCode($config->code)->first();
            if (! $setting) {
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
