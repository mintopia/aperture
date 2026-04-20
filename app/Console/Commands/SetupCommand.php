<?php

namespace App\Console\Commands;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aperture:setup
        {--admin-email= : Email for the first admin user}
        {--admin-password= : Password for the first admin user}
        {--admin-nickname= : Nickname for the first admin user}
        {--borealis-endpoint= : OAuth2 endpoint base URL}
        {--borealis-client-id= : OAuth2 client ID}
        {--borealis-client-secret= : OAuth2 client secret}';

    /**
     * @var string
     */
    protected $description = 'Bootstrap Aperture with roles, first admin user, and optional Borealis OAuth settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $adminRole = Role::query()->where('code', 'admin')->first() ?? new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();

        $userRole = Role::query()->where('code', 'user')->first() ?? new Role;
        $userRole->code = 'user';
        $userRole->name = 'User';
        $userRole->save();

        $firstUser = User::query()->orderBy('id')->first();
        if ($firstUser === null) {
            $adminEmail = (string) $this->option('admin-email');
            $adminPassword = (string) $this->option('admin-password');
            $adminNickname = (string) $this->option('admin-nickname');

            if ($adminEmail === '' || $adminPassword === '') {
                $this->error('First-time setup requires --admin-email and --admin-password options.');

                return self::FAILURE;
            }

            if ($adminNickname === '') {
                $adminNickname = Str::before($adminEmail, '@');
                if ($adminNickname === '') {
                    $adminNickname = 'admin';
                }
            }

            $firstUser = new User;
            $firstUser->email = $adminEmail;
            $firstUser->nickname = $adminNickname;
            $firstUser->password = $adminPassword;
            $firstUser->save();
            $firstUser->roles()->syncWithoutDetaching([$adminRole->id, $userRole->id]);
            $this->info(sprintf('Created first admin user: %s', $firstUser->email));
        } else {
            $firstUser->roles()->syncWithoutDetaching([$userRole->id]);
            if (! $firstUser->hasRole('admin')) {
                $firstUser->roles()->syncWithoutDetaching([$adminRole->id]);
                $this->info(sprintf('Granted admin role to first user: %s', $firstUser->email));
            }
        }

        $endpoint = trim((string) $this->option('borealis-endpoint'));
        $clientId = trim((string) $this->option('borealis-client-id'));
        $clientSecret = (string) $this->option('borealis-client-secret');
        $provided = array_filter([$endpoint, $clientId, $clientSecret], fn (string $value): bool => $value !== '');

        if ($provided !== [] && count($provided) < 3) {
            $this->error('Provide --borealis-endpoint, --borealis-client-id, and --borealis-client-secret together.');

            return self::FAILURE;
        }

        if ($provided !== []) {
            IntegrationConfig::setValue('borealis', 'endpoint', $endpoint);
            IntegrationConfig::setValue('borealis', 'client_id', $clientId);
            IntegrationConfig::setValue('borealis', 'client_secret', $clientSecret, true);
            IntegrationConfig::setValue(
                'borealis',
                'scope',
                (string) IntegrationConfig::getWithFallback('borealis', 'scope', 'discord')
            );
            CapabilityAssignment::assign('authentication', 'borealis');
            $this->info('Saved Borealis OAuth settings.');
        } else {
            $this->warn('Borealis OAuth settings were not provided. Captive portal device auth will stay unavailable.');
        }

        $setting = Setting::query()->where('code', 'auth.linkemails')->first() ?? new Setting;
        $setting->code = 'auth.linkemails';
        $setting->name = 'Link user auth by email';
        $setting->description = 'If a user authenticates using multiple methods, link them using their email address.';
        if (! $setting->exists) {
            $setting->value = true;
        }
        $setting->save();

        $this->info('Aperture setup complete.');

        return self::SUCCESS;
    }
}
