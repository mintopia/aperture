<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            [
                'code' => 'theme.name',
                'name' => 'Theme Name',
                'description' => 'The active colour theme for the application.',
                'value' => 'cool-neon',
            ],
            [
                'code' => 'theme.mode',
                'name' => 'Theme Mode',
                'description' => 'The default colour mode (light or dark).',
                'value' => 'dark',
            ],
        ];

        foreach ($settings as $config) {
            $setting = Setting::whereCode($config['code'])->first();
            if (! $setting) {
                $setting = new Setting;
                $setting->code = $config['code'];
                $setting->name = $config['name'];
                $setting->description = $config['description'];
                $setting->value = $config['value'];
                $setting->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::whereIn('code', ['theme.name', 'theme.mode'])->delete();
    }
};
