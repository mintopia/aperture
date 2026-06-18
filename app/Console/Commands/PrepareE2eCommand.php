<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class PrepareE2eCommand extends Command
{
    protected $signature = 'aperture:e2e:prepare
        {--email=playwright-admin@example.test : Admin email used by Playwright}
        {--password=playwright-password : Admin password used by Playwright}
        {--nickname=playwright-admin : Admin nickname used by Playwright}
        {--verify-redis : Assert redis connectivity for cache/session path}';

    protected $description = 'Prepare deterministic Playwright E2E fixtures (admin user + switch + ports)';

    public function handle(): int
    {
        if ((bool) $this->option('verify-redis') && ! $this->verifyRedisConnections()) {
            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $password = (string) $this->option('password');
        $nickname = (string) $this->option('nickname');

        [$admin, $switch] = Model::unguarded(function () use ($email, $password, $nickname): array {
            $adminRole = Role::query()->firstOrCreate(
                ['code' => 'admin'],
                ['name' => 'Admin']
            );

            $userRole = Role::query()->firstOrCreate(
                ['code' => 'user'],
                ['name' => 'User']
            );

            $admin = User::query()->where('email', $email)->first() ?? new User;
            $admin->email = $email;
            $admin->nickname = $nickname;
            $admin->password = $password;
            $admin->save();

            $admin->roles()->syncWithoutDetaching([$adminRole->id, $userRole->id]);

            $switch = SwitchConfig::query()->updateOrCreate(
                ['hostname' => 'playwright-switch.local'],
                [
                    'name' => 'Playwright Switch',
                    'type' => 'cisco',
                    'username' => 'admin',
                    'password' => 'password123',
                    'enable_password' => 'enable123',
                    'enabled' => true,
                    'port' => 22,
                    'timeout' => 5,
                ]
            );

            SwitchPort::query()->updateOrCreate(
                [
                    'switch_config_id' => $switch->id,
                    'port_name' => 'Gi1/0/1',
                ],
                [
                    'port_number' => 'Gi1/0/1',
                    'switch_description' => 'Playwright seeded access port',
                    'admin_notes' => null,
                    'access_vlan' => 10,
                    'switchport_mode' => 'access',
                    'speed' => '1000',
                    'status' => 'up',
                    'admin_status' => 'up',
                    'duplex' => 'full',
                    'poe_status' => 'on',
                    'last_synced_at' => now(),
                ]
            );

            SwitchPort::query()->updateOrCreate(
                [
                    'switch_config_id' => $switch->id,
                    'port_name' => 'Gi1/0/2',
                ],
                [
                    'port_number' => 'Gi1/0/2',
                    'switch_description' => 'Playwright seeded uplink port',
                    'admin_notes' => null,
                    'access_vlan' => null,
                    'switchport_mode' => 'trunk',
                    'speed' => '1000',
                    'status' => 'connected',
                    'admin_status' => 'up',
                    'duplex' => 'full',
                    'poe_status' => null,
                    'last_synced_at' => now(),
                ]
            );

            return [$admin, $switch];
        });

        foreach (['127.0.0.1', '::1'] as $ip) {
            RateLimiter::clear('login-attempt:'.Str::lower($email).'|'.$ip);
        }

        // Use create() directly to avoid the AuditLogRecorded broadcast which
        // requires a running Pusher/Reverb connection (unavailable in E2E sandboxes).
        AuditLog::create([
            'action' => 'user.login',
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->getKey(),
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'process' => 'e2e',
            'severity' => 'info',
        ]);

        AuditLog::create([
            'action' => 'switch.synced',
            'subject_type' => $switch->getMorphClass(),
            'subject_id' => $switch->getKey(),
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'process' => 'e2e',
            'severity' => 'warning',
        ]);

        $this->info(sprintf('Playwright fixtures prepared. Admin=%s, Switch=%s', $email, $switch->hostname));

        return self::SUCCESS;
    }

    private function verifyRedisConnections(): bool
    {
        $sessionDriver = (string) config('session.driver');
        $cacheDriver = (string) config('cache.default');

        $mustVerifyRedis = $sessionDriver === 'redis' || $cacheDriver === 'redis';
        if (! $mustVerifyRedis) {
            $this->warn(sprintf(
                'Redis verification skipped: session.driver=%s, cache.default=%s',
                $sessionDriver,
                $cacheDriver
            ));

            return true;
        }

        $connections = ['default'];
        if ($cacheDriver === 'redis') {
            $connections[] = 'cache';
        }

        foreach (array_unique($connections) as $connection) {
            try {
                Redis::connection($connection)->command('PING');
            } catch (Throwable $throwable) {
                $this->error(sprintf(
                    'Redis connection [%s] failed while preparing E2E fixtures: %s',
                    $connection,
                    $throwable->getMessage()
                ));

                return false;
            }
        }

        $this->info('Redis verification passed for configured cache/session path.');

        return true;
    }
}
