<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\KeaTester;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeaTesterTest extends TestCase
{
    private KeaTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new KeaTester;
    }

    private function fakeListCommands(array $commands, int $status = 200): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'text' => 'list-commands', 'arguments' => $commands],
            ], $status),
        ]);
    }

    public function test_success_when_required_commands_present(): void
    {
        $this->fakeListCommands(['build-report', 'config-get', 'lease4-get-page']);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('successfully', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertSame('https://kea.local', $result->requestUrl);
    }

    public function test_no_endpoint_configured_does_not_send_a_request(): void
    {
        Http::fake();

        $result = $this->tester->connect([]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No Endpoint configured', $result->message);
        Http::assertNothingSent();
    }

    public function test_empty_string_endpoint_does_not_send_a_request(): void
    {
        Http::fake();

        $result = $this->tester->connect(['endpoint_v4' => '', 'endpoint_v6' => '']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No Endpoint configured', $result->message);
        Http::assertNothingSent();
    }

    public function test_authentication_failure_on_401(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint_v4' => 'https://kea.local',
            'username_v4' => 'admin',
            'password_v4' => 'wrong',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Authentication failed', $result->message);
        $this->assertSame(401, $result->responseStatus);
    }

    public function test_authentication_failure_on_403(): void
    {
        Http::fake(['kea.local' => Http::response('Forbidden', 403)]);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Authentication failed', $result->message);
        $this->assertSame(403, $result->responseStatus);
    }

    public function test_network_failure_is_reported_distinctly_from_auth_failure(): void
    {
        Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection timed out')]);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Could not reach', $result->message);
    }

    public function test_other_http_failure_is_reported_as_could_not_reach(): void
    {
        Http::fake(['kea.local' => Http::response('Server Error', 500)]);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Could not reach', $result->message);
        $this->assertSame(500, $result->responseStatus);
    }

    public function test_kea_level_error_is_reported(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 1, 'text' => 'command not supported'],
            ]),
        ]);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('command not supported', $result->message);
    }

    public function test_missing_lease4_get_page_command_is_named(): void
    {
        $this->fakeListCommands(['config-get']);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('lease_cmds', $result->message);
        $this->assertStringContainsString('lease4-get-page', $result->message);
    }

    public function test_missing_config_get_command_is_named(): void
    {
        $this->fakeListCommands(['lease4-get-page']);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('config-get', $result->message);
    }

    public function test_missing_both_commands_names_both(): void
    {
        $this->fakeListCommands(['build-report']);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('lease4-get-page', $result->message);
        $this->assertStringContainsString('config-get', $result->message);
    }

    public function test_sends_service_parameter_in_command_body(): void
    {
        $this->fakeListCommands(['config-get', 'lease4-get-page']);

        $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'list-commands'
                && $data['service'] === ['dhcp4'];
        });
    }

    public function test_applies_basic_auth_when_credentials_configured(): void
    {
        $this->fakeListCommands(['config-get', 'lease4-get-page']);

        $this->tester->connect([
            'endpoint_v4' => 'https://kea.local',
            'username_v4' => 'admin',
            'password_v4' => 'secret',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && str_starts_with($auth[0], 'Basic ');
        });
    }

    public function test_omits_basic_auth_header_when_no_credentials_configured(): void
    {
        $this->fakeListCommands(['config-get', 'lease4-get-page']);

        $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth);
        });
    }

    public function test_success_v6_when_required_commands_present(): void
    {
        $this->fakeListCommands(['build-report', 'config-get', 'lease6-get-page']);

        $result = $this->tester->connect(['endpoint_v6' => 'https://kea.local']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('IPv6 Endpoint', $result->message);
        $this->assertStringContainsString('successfully', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertSame('https://kea.local', $result->requestUrl);
    }

    public function test_missing_lease6_get_page_command_is_named(): void
    {
        $this->fakeListCommands(['config-get']);

        $result = $this->tester->connect(['endpoint_v6' => 'https://kea.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('IPv6 Endpoint', $result->message);
        $this->assertStringContainsString('lease_cmds', $result->message);
        $this->assertStringContainsString('lease6-get-page', $result->message);
    }

    public function test_sends_service_parameter_v6_in_command_body(): void
    {
        $this->fakeListCommands(['config-get', 'lease6-get-page']);

        $this->tester->connect(['endpoint_v6' => 'https://kea.local']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'list-commands'
                && $data['service'] === ['dhcp6'];
        });
    }

    public function test_authentication_failure_v6_on_401(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint_v6' => 'https://kea.local',
            'username_v6' => 'admin',
            'password_v6' => 'wrong',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('IPv6 Endpoint', $result->message);
        $this->assertStringContainsString('Authentication failed', $result->message);
        $this->assertSame(401, $result->responseStatus);
    }

    public function test_v4_only_config_sends_exactly_one_request(): void
    {
        $this->fakeListCommands(['config-get', 'lease4-get-page']);

        $result = $this->tester->connect(['endpoint_v4' => 'https://kea.local']);

        $this->assertTrue($result->success);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['service'] === ['dhcp4'];
        });
    }

    public function test_dual_endpoint_both_succeed(): void
    {
        Http::fake([
            'kea-v4.local' => Http::response([
                ['result' => 0, 'text' => 'list-commands', 'arguments' => ['config-get', 'lease4-get-page']],
            ]),
            'kea-v6.local' => Http::response([
                ['result' => 0, 'text' => 'list-commands', 'arguments' => ['config-get', 'lease6-get-page']],
            ]),
        ]);

        $result = $this->tester->connect([
            'endpoint_v4' => 'https://kea-v4.local',
            'endpoint_v6' => 'https://kea-v6.local',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('IPv4 Endpoint', $result->message);
        $this->assertStringContainsString('IPv6 Endpoint', $result->message);
        $this->assertStringContainsString('successfully', $result->message);
        $this->assertNull($result->requestMethod);
        $this->assertNull($result->requestUrl);
    }

    public function test_dual_endpoint_v4_succeeds_v6_fails(): void
    {
        Http::fake([
            'kea-v4.local' => Http::response([
                ['result' => 0, 'text' => 'list-commands', 'arguments' => ['config-get', 'lease4-get-page']],
            ]),
            'kea-v6.local' => Http::response([
                ['result' => 0, 'text' => 'list-commands', 'arguments' => ['config-get']],
            ]),
        ]);

        $result = $this->tester->connect([
            'endpoint_v4' => 'https://kea-v4.local',
            'endpoint_v6' => 'https://kea-v6.local',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('IPv4 Endpoint successfully', $result->message);
        $this->assertStringContainsString('IPv6 Endpoint', $result->message);
        $this->assertStringContainsString('lease6-get-page', $result->message);
        $this->assertNull($result->requestMethod);
        $this->assertNull($result->requestUrl);
    }
}
