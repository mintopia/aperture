<?php

namespace Database\Seeders;

use App\Models\AuthProvider;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AuthProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $providers = [
            (object)[
                'name' => 'Discord',
                'code' => 'discord',
                'class' => 'App\\Services\\Auth\\DiscordAuth',
                'enabled' => true,
            ],
            (object)[
                'name' => 'Steam',
                'code' => 'steam',
                'class' => 'App\\Services\\Auth\\SteamAuth',
                'enabled' => true,
            ],
        ];
        foreach ($providers as $config) {
            $provider = AuthProvider::whereCode($config->code)->first();
            if (!$provider) {
                $provider = new AuthProvider;
                $provider->code = $config->code;
            }
            $provider->name = $config->name;
            $provider->class = $config->class;
            $provider->enabled = $config->enabled;
            $provider->save();
        }
    }
}
