<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddressMacAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkSettingsClearIpMacTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string CLEAR_URL = '/admin/settings/network/ip-mac-mappings/clear';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-10 12:00:00'));
    }

    protected function createAdminUser(?string $password = 'password'): User
    {
        $factory = User::factory();
        if ($password !== null) {
            $factory = $factory->withPassword($password);
        }

        $user = $factory->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    #[Test]
    public function clears_only_mappings_strictly_older_than_given_days(): void
    {
        $admin = $this->createAdminUser();

        $stale = IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(31)]);
        $boundary = IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(30)]);
        $recent = IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(5)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('ip_address_mac_address', ['id' => $stale->id]);
        $this->assertDatabaseHas('ip_address_mac_address', ['id' => $boundary->id]);
        $this->assertDatabaseHas('ip_address_mac_address', ['id' => $recent->id]);
    }

    #[Test]
    public function wrong_password_is_rejected_and_nothing_deleted(): void
    {
        $admin = $this->createAdminUser('correct-password');

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(100)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertSame(1, IpAddressMacAddress::count());
    }

    #[Test]
    public function user_without_password_is_rejected_and_nothing_deleted(): void
    {
        $admin = $this->createAdminUser(null);

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(100)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'any-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertSame(1, IpAddressMacAddress::count());
    }

    #[Test]
    public function missing_password_fails_validation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
        ]);

        $response->assertSessionHasErrors('password');
    }

    #[Test]
    public function missing_days_fails_validation(): void
    {
        $admin = $this->createAdminUser();

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(100)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('days');
        $this->assertSame(1, IpAddressMacAddress::count());
    }

    #[Test]
    public function zero_days_fails_validation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 0,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('days');
    }

    #[Test]
    public function non_integer_days_fails_validation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 'not-a-number',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('days');
    }

    #[Test]
    public function days_above_maximum_fails_validation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 3651,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('days');
    }

    #[Test]
    public function successful_clear_records_audit_log_with_days_and_deleted_count(): void
    {
        $admin = $this->createAdminUser();

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(45)]);
        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(60)]);
        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(2)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'network.ip_mac_mappings.cleared')->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->metadata);
        $this->assertSame(30, $log->metadata['days']);
        $this->assertSame(2, $log->metadata['deleted']);
    }

    #[Test]
    public function successful_clear_flashes_success_message_with_deleted_count(): void
    {
        $admin = $this->createAdminUser();

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(45)]);
        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(60)]);

        $response = $this->actingAs($admin)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertStringContainsString('2', (string) session('success'));
    }

    #[Test]
    public function non_admin_cannot_clear_mappings(): void
    {
        $user = User::factory()->withPassword('password')->create();

        IpAddressMacAddress::factory()->create(['last_seen_at' => now()->subDays(100)]);

        $response = $this->actingAs($user)->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'password',
        ]);

        $response->assertForbidden();
        $this->assertSame(1, IpAddressMacAddress::count());
    }

    #[Test]
    public function unauthenticated_user_is_redirected(): void
    {
        $response = $this->post(self::CLEAR_URL, [
            'days' => 30,
            'password' => 'password',
        ]);

        $response->assertRedirect('/captive');
    }
}
