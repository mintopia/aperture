<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpPolicyServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_apply_user_policy_sets_ip_fields_to_match_user(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => true,
            'internet_blocked' => false,
        ]);
        $ip = IpAddress::factory()->create();

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertTrue($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertTrue($ip->dns_filtering_enabled);
    }

    public function test_apply_user_policy_forces_internet_disabled_when_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => true,
            'internet_blocked' => true,
        ]);
        $ip = IpAddress::factory()->create();

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertFalse($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertTrue($ip->dns_filtering_enabled);
    }

    public function test_apply_user_policy_does_not_save_when_already_matching(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
            'internet_blocked' => false,
        ]);
        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $originalUpdatedAt = $ip->updated_at;

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_apply_defaults_sets_all_fields_to_false(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => true,
            'dns_filtering_enabled' => true,
        ]);

        $service = new IpPolicyService;
        $service->applyDefaults($ip);

        $ip->refresh();
        $this->assertFalse($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertFalse($ip->dns_filtering_enabled);
    }

    public function test_apply_defaults_does_not_save_when_already_default(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => false,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $originalUpdatedAt = $ip->updated_at;

        $service = new IpPolicyService;
        $service->applyDefaults($ip);

        $ip->refresh();
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }
}
