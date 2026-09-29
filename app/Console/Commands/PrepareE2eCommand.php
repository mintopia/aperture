<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ContentBlock;
use App\Models\DhcpLease;
use App\Models\DhcpRangeRecord;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\UserIpAddress;
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

        $this->prepareDedicatedUser('playwright-passkey@example.test', 'playwright-passkey', 'playwright-passkey-password');

        $this->prepareAttendeeFixtures();
        $this->prepareAdminJourneyFixtures();

        foreach (['127.0.0.1', '::1'] as $ip) {
            RateLimiter::clear('login-attempt:'.Str::lower($email).'|'.$ip);
        }

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

    private function prepareDedicatedUser(string $email, string $nickname, string $password): void
    {
        Model::unguarded(function () use ($email, $nickname, $password): void {
            $roleIds = Role::query()->whereIn('code', ['admin', 'user'])->pluck('id')->all();

            $user = User::query()->where('email', $email)->first() ?? new User;
            $user->email = $email;
            $user->nickname = $nickname;
            $user->password = $password;
            $user->save();

            $user->roles()->syncWithoutDetaching($roleIds);
        });
    }

    private function prepareAttendeeFixtures(): void
    {
        Model::unguarded(function (): void {
            $userRole = Role::query()->firstOrCreate(['code' => 'user'], ['name' => 'User']);

            foreach ([
                ['playwright-attendee@example.test', 'playwright-attendee'],
                ['playwright-account@example.test', 'playwright-account'],
            ] as [$email, $nickname]) {
                $user = User::query()->where('email', $email)->first() ?? new User;
                $user->email = $email;
                $user->nickname = $nickname;
                $user->password = 'playwright-attendee-password';
                $user->dns_filtering_enabled = false;
                $user->save();
                $user->roles()->syncWithoutDetaching([$userRole->id]);
            }

            Page::query()->updateOrCreate(
                ['slug' => 'playwright-page'],
                ['title' => 'Playwright Page', 'content' => "Hello **e2e** attendee.\n\n<script>window.__pwInjected = true</script>"]
            );

            foreach ([
                ['connection_strip', 'Connection Status', null, 1, 1, 3],
                ['bandwidth', 'Your Bandwidth', null, 1, 2, 2],
                ['dns_filter', 'DNS Ad Blocking', 'Toggle DNS filtering for your connection.', 3, 2, 1],
            ] as [$type, $title, $content, $col, $row, $colSpan]) {
                ContentBlock::query()->updateOrCreate(
                    ['type' => $type],
                    ['title' => $title, 'content' => $content, 'grid_col' => $col, 'grid_row' => $row, 'col_span' => $colSpan, 'row_span' => 1, 'is_active' => true]
                );
            }
        });

        Setting::set('dns.check_url', 'DNS check URL', 'http://dns-check.e2e.invalid/{uuid}');
        Setting::set('dns.warning_message', 'DNS warning message', 'Playwright DNS warning');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'http://ipv6-check.e2e.invalid/{uuid}');
    }

    private function prepareAdminJourneyFixtures(): void
    {
        Model::unguarded(function (): void {
            $userRole = Role::query()->firstOrCreate(['code' => 'user'], ['name' => 'User']);

            $makeUser = function (string $nickname) use ($userRole): User {
                $user = User::query()->where('email', $nickname.'@example.test')->first() ?? new User;
                $user->email = $nickname.'@example.test';
                $user->nickname = $nickname;
                $user->internet_blocked = false;
                $user->save();
                $user->roles()->syncWithoutDetaching([$userRole->id]);

                return $user;
            };

            $makeIp = function (string $address, bool $internet) {
                $ip = IpAddress::query()->firstOrNew(['address' => $address]);
                $ip->internet_enabled = $internet;
                $ip->rate_limit_enabled = false;
                $ip->dns_filtering_enabled = false;
                $ip->last_seen_at = now();
                $ip->save();

                return $ip;
            };

            $makeMac = function (string $mac, string $source, ?User $owner, IpAddress $ip) {
                $record = MacAddress::query()->updateOrCreate(
                    ['mac_address' => $mac],
                    ['source' => $source, 'user_id' => $owner?->id, 'description' => null]
                );
                $ip->macAddresses()->syncWithoutDetaching([$record->id => ['source' => $source, 'last_seen_at' => now()]]);

                return $record;
            };

            $makeUser('pw-block-user');
            $makeUser('pw-edit-user');
            $netUser = $makeUser('pw-net-user');

            $ipA = $makeIp('10.99.0.11', false);
            $ipB = $makeIp('10.99.0.12', false);
            $ipC = $makeIp('10.99.5.20', true);
            $makeIp('10.99.1.10', true);

            foreach ([$ipA, $ipB] as $ip) {
                UserIpAddress::query()->updateOrCreate(
                    ['user_id' => $netUser->id, 'ip_address_id' => $ip->id],
                    ['last_seen_at' => now()]
                );
            }

            $macA = $makeMac('02:99:00:00:00:01', 'static', $netUser, $ipA);
            $macB = $makeMac('02:99:00:00:00:02', 'snmp', null, $ipB);
            $macC = $makeMac('02:99:00:00:00:03', 'dhcp', null, $ipC);

            foreach ([
                ['10.99.0.0/24', '10.99.0.10', '10.99.0.200'],
                ['10.99.5.0/24', '10.99.5.10', '10.99.5.200'],
            ] as [$subnet, $from, $to]) {
                DhcpRangeRecord::query()->updateOrCreate(
                    ['integration' => 'kea', 'subnet' => $subnet],
                    ['interface' => 'pw-'.$subnet, 'type' => 'ipv4', 'range_from' => $from, 'range_to' => $to, 'prefix' => $subnet, 'total_addresses' => '191', 'used_addresses' => 1, 'utilisation' => 0.01]
                );
            }

            foreach ([[$ipA, $macA, 'pw-laptop'], [$ipB, $macB, 'pw-console'], [$ipC, $macC, 'pw-remote']] as [$ip, $mac, $hostname]) {
                DhcpLease::query()->updateOrCreate(
                    ['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id],
                    ['integration' => 'kea', 'hostname' => $hostname, 'expires_at' => now()->addDay()]
                );
            }
        });
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
