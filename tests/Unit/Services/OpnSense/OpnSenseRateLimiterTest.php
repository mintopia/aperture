<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseRateLimiter;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

class OpnSenseRateLimiterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private OpnSenseClient&MockObject $client;

    private OpnSenseRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(OpnSenseClient::class);
        $this->limiter = new OpnSenseRateLimiter(
            $this->client,
            uploadRuleUuid: 'up-uuid',
            downloadRuleUuid: 'down-uuid',
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_implements_rate_limiting_interface(): void
    {
        $this->assertInstanceOf(RateLimitingInterface::class, $this->limiter);
    }

    // --- limitIp tests ---

    public function test_limit_ip_adds_host_to_download_and_upload_rules_and_reconfigures(): void
    {
        $downloadRule = $this->makeRuleResponse();
        $uploadRule = $this->makeRuleResponse();

        $callIndex = 0;
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $uri) use (&$callIndex, $downloadRule, $uploadRule): stdClass {
                $callIndex++;
                if ($callIndex === 1) {
                    $this->assertSame('/api/trafficshaper/settings/get_rule/down-uuid', $uri);

                    return $downloadRule;
                }

                $this->assertSame('/api/trafficshaper/settings/get_rule/up-uuid', $uri);

                return $uploadRule;
            });

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if ($postCallIndex === 1) {
                    $this->assertSame('/api/trafficshaper/settings/set_rule/down-uuid', $uri);
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('10.0.0.50', $payload->rule->destination);

                    return (object) ['result' => 'saved'];
                }

                if ($postCallIndex === 2) {
                    $this->assertSame('/api/trafficshaper/settings/set_rule/up-uuid', $uri);
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('10.0.0.50', $payload->rule->source);

                    return (object) ['result' => 'saved'];
                }

                $this->assertSame('/api/trafficshaper/service/reconfigure', $uri);

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('10.0.0.50');
    }

    public function test_limit_ip_preserves_existing_hosts(): void
    {
        $downloadRule = $this->makeRuleResponse(['10.0.0.10']);
        $uploadRule = $this->makeRuleResponse(['10.0.0.10']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('10.0.0.10,10.0.0.50', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('10.0.0.10,10.0.0.50', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('10.0.0.50');
    }

    public function test_limit_ip_deduplicates_existing_host(): void
    {
        $downloadRule = $this->makeRuleResponse(['10.0.0.50']);
        $uploadRule = $this->makeRuleResponse(['10.0.0.50']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('10.0.0.50', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('10.0.0.50', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('10.0.0.50');
    }

    public function test_limit_ip_throws_on_failed_save(): void
    {
        $downloadRule = $this->makeRuleResponse();

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($downloadRule);

        $this->client->expects($this->once())
            ->method('post')
            ->willReturn((object) ['result' => 'failed']);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Unable to update shaper rule');

        $this->limiter->limitIp('10.0.0.50');
    }

    // --- unlimitIp tests ---

    public function test_unlimit_ip_removes_host_from_download_and_upload_rules_and_reconfigures(): void
    {
        $downloadRule = $this->makeRuleResponse(['10.0.0.50']);
        $uploadRule = $this->makeRuleResponse(['10.0.0.50']);

        $callIndex = 0;
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $uri) use (&$callIndex, $downloadRule, $uploadRule): stdClass {
                $callIndex++;
                if ($callIndex === 1) {
                    $this->assertSame('/api/trafficshaper/settings/get_rule/down-uuid', $uri);

                    return $downloadRule;
                }

                $this->assertSame('/api/trafficshaper/settings/get_rule/up-uuid', $uri);

                return $uploadRule;
            });

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if ($postCallIndex === 1) {
                    $this->assertSame('/api/trafficshaper/settings/set_rule/down-uuid', $uri);
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('', $payload->rule->destination);

                    return (object) ['result' => 'saved'];
                }

                if ($postCallIndex === 2) {
                    $this->assertSame('/api/trafficshaper/settings/set_rule/up-uuid', $uri);
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('', $payload->rule->source);

                    return (object) ['result' => 'saved'];
                }

                $this->assertSame('/api/trafficshaper/service/reconfigure', $uri);

                return (object) ['status' => 'ok'];
            });

        $this->limiter->unlimitIp('10.0.0.50');
    }

    public function test_unlimit_ip_preserves_other_hosts(): void
    {
        $downloadRule = $this->makeRuleResponse(['10.0.0.10', '10.0.0.50']);
        $uploadRule = $this->makeRuleResponse(['10.0.0.10', '10.0.0.50']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('10.0.0.10', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('10.0.0.10', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->unlimitIp('10.0.0.50');
    }

    public function test_unlimit_ip_handles_ip_not_in_rule(): void
    {
        $downloadRule = $this->makeRuleResponse();
        $uploadRule = $this->makeRuleResponse();

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->unlimitIp('10.0.0.99');
    }

    public function test_unlimit_ip_throws_on_failed_save(): void
    {
        $downloadRule = $this->makeRuleResponse(['10.0.0.50']);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($downloadRule);

        $this->client->expects($this->once())
            ->method('post')
            ->willReturn((object) ['result' => 'failed']);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Unable to update shaper rule');

        $this->limiter->unlimitIp('10.0.0.50');
    }

    // --- updateShaperRule payload tests ---

    public function test_update_shaper_rule_sends_correct_payload_fields(): void
    {
        $rule = $this->makeRuleResponse([], '8080', '443');

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($rule);

        $this->client->expects($this->atLeastOnce())
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []): stdClass {
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('8080', $payload->rule->dst_port);
                    $this->assertSame('443', $payload->rule->src_port);
                    $this->assertSame('Rate limit', $payload->rule->description);
                    $this->assertSame('1', $payload->rule->enabled);
                    $this->assertSame('in', $payload->rule->direction);
                    $this->assertSame('wan', $payload->rule->interface);
                    $this->assertSame('pipe1', $payload->rule->target);

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        // Use reflection to test addHostToRule directly
        $reflection = new ReflectionClass($this->limiter);
        $method = $reflection->getMethod('addHostToRule');
        $method->invoke($this->limiter, 'down-uuid', '10.0.0.50', 'destination');
    }

    // --- reconcile tests ---

    public function test_reconcile_adds_missing_rate_limited_ips(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.50', 'rate_limit_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.60', 'rate_limit_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.70', 'rate_limit_enabled' => false]);

        // fetchRateLimitedIps: get download rule (empty)
        $emptyRule = $this->makeRuleResponse();

        $this->client->expects($this->exactly(5))
            ->method('get')
            ->willReturn($emptyRule);

        $this->client->expects($this->exactly(6))
            ->method('post')
            ->willReturnCallback(function (string $uri): stdClass {
                if (str_contains($uri, 'set_rule')) {
                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $result = $this->limiter->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.50', $result->added);
        $this->assertContains('10.0.0.60', $result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_removes_extra_rate_limited_ips(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.50', 'rate_limit_enabled' => false]);

        $ruleWithIp = $this->makeRuleResponse(['10.0.0.50']);

        $this->client->expects($this->exactly(3))
            ->method('get')
            ->willReturn($ruleWithIp);

        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri): stdClass {
                if (str_contains($uri, 'set_rule')) {
                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $result = $this->limiter->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.50', $result->removed);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_reports_unchanged_ips(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.50', 'rate_limit_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.60', 'rate_limit_enabled' => false]);

        $ruleWithIp = $this->makeRuleResponse(['10.0.0.50']);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($ruleWithIp);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->limiter->reconcile();

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertContains('10.0.0.50', $result->unchanged);
        $this->assertNotContains('10.0.0.60', $result->unchanged);
        $this->assertEmpty($result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->errors);
    }

    public function test_reconcile_dry_run_skips_actions(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.50', 'rate_limit_enabled' => true]);

        $emptyRule = $this->makeRuleResponse();

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($emptyRule);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->limiter->reconcile(dryRun: true);

        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame(['10.0.0.50'], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_captures_errors_per_ip(): void
    {
        IpAddress::factory()->create(['address' => '10.0.0.50', 'rate_limit_enabled' => true]);
        IpAddress::factory()->create(['address' => '10.0.0.60', 'rate_limit_enabled' => true]);

        $emptyRule = $this->makeRuleResponse();

        $getCallIndex = 0;
        $this->client->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function () use (&$getCallIndex, $emptyRule): stdClass {
                $getCallIndex++;
                if ($getCallIndex === 1) {
                    return $emptyRule;
                }

                throw new BackendException('Connection refused');
            });

        $result = $this->limiter->reconcile();

        $this->assertContains('10.0.0.50', $result->added);
        $this->assertContains('10.0.0.60', $result->added);
        $this->assertCount(2, $result->errors);
        $this->assertStringContainsString('10.0.0.50: Connection refused', $result->errors[0]);
        $this->assertStringContainsString('10.0.0.60: Connection refused', $result->errors[1]);
    }

    public function test_reconcile_handles_empty_state(): void
    {
        $emptyRule = $this->makeRuleResponse();

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($emptyRule);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->limiter->reconcile();

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_correctly_categorises_large_ip_sets(): void
    {
        // Create a mix of desired IPs: 50 that should be unchanged, 50 that should be added
        $unchangedIps = [];
        $addedIps = [];
        for ($i = 1; $i <= 50; $i++) {
            $ip = '10.2.0.'.$i;
            $unchangedIps[] = $ip;
            IpAddress::factory()->create(['address' => $ip, 'rate_limit_enabled' => true]);
        }

        for ($i = 51; $i <= 100; $i++) {
            $ip = '10.2.0.'.$i;
            $addedIps[] = $ip;
            IpAddress::factory()->create(['address' => $ip, 'rate_limit_enabled' => true]);
        }

        // Current IPs include the 50 unchanged + 30 that should be removed
        $removedIps = [];
        $currentIps = $unchangedIps;
        for ($i = 101; $i <= 130; $i++) {
            $ip = '10.2.0.'.$i;
            $removedIps[] = $ip;
            $currentIps[] = $ip;
        }

        $ruleWithCurrentIps = $this->makeRuleResponse($currentIps);

        $this->client->expects($this->atLeastOnce())
            ->method('get')
            ->willReturn($ruleWithCurrentIps);

        $this->client->expects($this->atLeastOnce())
            ->method('post')
            ->willReturnCallback(function (string $uri): stdClass {
                if (str_contains($uri, 'set_rule')) {
                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $result = $this->limiter->reconcile();

        $resultAdded = $result->added;
        $resultRemoved = $result->removed;
        $resultUnchanged = $result->unchanged;
        sort($resultAdded);
        sort($resultRemoved);
        sort($resultUnchanged);
        sort($addedIps);
        sort($removedIps);
        sort($unchangedIps);

        $this->assertSame($addedIps, $resultAdded);
        $this->assertSame($removedIps, $resultRemoved);
        $this->assertSame($unchangedIps, $resultUnchanged);
        $this->assertSame([], $result->errors);
    }

    // --- IPv6 normalization at the OPNsense boundary ---

    public function test_limit_ip_sends_uppercase_ipv6_lowercased(): void
    {
        $downloadRule = $this->makeRuleResponse();
        $uploadRule = $this->makeRuleResponse();

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if ($postCallIndex === 1) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('2001:db8::abcd', $payload->rule->destination);

                    return (object) ['result' => 'saved'];
                }

                if ($postCallIndex === 2) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    $this->assertSame('2001:db8::abcd', $payload->rule->source);

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('2001:DB8::ABCD');
    }

    public function test_limit_ip_does_not_duplicate_case_mismatched_ipv6(): void
    {
        // The rule already contains the lowercase form; adding the uppercase
        // form must not create a duplicate entry.
        $downloadRule = $this->makeRuleResponse(['2001:db8::1']);
        $uploadRule = $this->makeRuleResponse(['2001:db8::1']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('2001:db8::1', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('2001:db8::1', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('2001:DB8::1');
    }

    public function test_limit_ip_ipv4_passes_through_and_existing_ipv6_hosts_are_normalized(): void
    {
        // A stale uppercase IPv6 entry on the firewall must be rewritten
        // lowercase, while the IPv4 being added passes through byte-identical.
        $downloadRule = $this->makeRuleResponse(['2001:DB8::1']);
        $uploadRule = $this->makeRuleResponse(['2001:DB8::1']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('2001:db8::1,192.0.2.10', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('2001:db8::1,192.0.2.10', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->limitIp('192.0.2.10');
    }

    public function test_unlimit_ip_uppercase_input_removes_lowercase_host(): void
    {
        $downloadRule = $this->makeRuleResponse(['2001:db8::1']);
        $uploadRule = $this->makeRuleResponse(['2001:db8::1']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->unlimitIp('2001:DB8::1');
    }

    public function test_unlimit_ip_removes_uppercase_host_reported_by_firewall(): void
    {
        $downloadRule = $this->makeRuleResponse(['2001:DB8::1']);
        $uploadRule = $this->makeRuleResponse(['2001:DB8::1']);

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($downloadRule, $uploadRule);

        $postCallIndex = 0;
        $this->client->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $uri, array $query = [], array|stdClass|null $payload = []) use (&$postCallIndex): stdClass {
                $postCallIndex++;
                if (str_contains($uri, 'set_rule')) {
                    $this->assertInstanceOf(stdClass::class, $payload);
                    if ($postCallIndex === 1) {
                        $this->assertSame('', $payload->rule->destination);
                    }

                    if ($postCallIndex === 2) {
                        $this->assertSame('', $payload->rule->source);
                    }

                    return (object) ['result' => 'saved'];
                }

                return (object) ['status' => 'ok'];
            });

        $this->limiter->unlimitIp('2001:db8::1');
    }

    public function test_reconcile_treats_case_mismatched_ipv6_as_unchanged(): void
    {
        IpAddress::factory()->create(['address' => '2001:db8::1', 'rate_limit_enabled' => true]);

        // Firewall reports the rate-limited host in uppercase; DB stores lowercase.
        $ruleWithUppercaseIp = $this->makeRuleResponse(['2001:DB8::1']);

        $this->client->method('get')
            ->willReturn($ruleWithUppercaseIp);

        $this->client->expects($this->never())
            ->method('post');

        $result = $this->limiter->reconcile();

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame(['2001:db8::1'], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    // --- filter helper tests ---

    #[AllowMockObjectsWithoutExpectations]
    public function test_filter_extracts_selected_keys(): void
    {
        $reflection = new ReflectionClass($this->limiter);
        $method = $reflection->getMethod('filter');

        $objects = (object) [
            '10.0.0.1' => (object) ['value' => '10.0.0.1', 'selected' => true],
            '10.0.0.2' => (object) ['value' => '10.0.0.2', 'selected' => false],
            '10.0.0.3' => (object) ['value' => '10.0.0.3', 'selected' => true],
        ];

        $result = $method->invoke($this->limiter, $objects);

        $this->assertSame(['10.0.0.1', '10.0.0.3'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_filter_handles_empty_object(): void
    {
        $reflection = new ReflectionClass($this->limiter);
        $method = $reflection->getMethod('filter');

        $result = $method->invoke($this->limiter, (object) []);

        $this->assertSame([], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_filter_handles_array_input(): void
    {
        $reflection = new ReflectionClass($this->limiter);
        $method = $reflection->getMethod('filter');

        $objects = [
            '10.0.0.1' => (object) ['value' => '10.0.0.1', 'selected' => true],
        ];

        $result = $method->invoke($this->limiter, $objects);

        $this->assertSame(['10.0.0.1'], $result);
    }

    /**
     * @param  array<int, string>  $ips
     */
    private function makeRuleResponse(array $ips = [], string $dstPort = '', string $srcPort = ''): stdClass
    {
        $destination = new stdClass;
        $source = new stdClass;
        foreach ($ips as $ip) {
            $destination->{$ip} = (object) ['value' => $ip, 'selected' => true];
            $source->{$ip} = (object) ['value' => $ip, 'selected' => true];
        }

        return (object) [
            'rule' => (object) [
                'description' => 'Rate limit',
                'destination_not' => '0',
                'direction' => (object) ['in' => (object) ['value' => 'in', 'selected' => true]],
                'dscp' => (object) [],
                'dst_port' => $dstPort,
                'enabled' => '1',
                'interface' => (object) ['wan' => (object) ['value' => 'wan', 'selected' => true]],
                'interface2' => (object) [],
                'iplen' => '',
                'proto' => (object) [],
                'sequence' => '1',
                'source_not' => '0',
                'src_port' => $srcPort,
                'target' => (object) ['pipe1' => (object) ['value' => 'pipe1', 'selected' => true]],
                'destination' => $destination,
                'source' => $source,
            ],
        ];
    }
}
